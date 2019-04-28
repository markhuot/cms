<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */


namespace craft\test;


use Codeception\Lib\ModuleContainer;
use Codeception\Module\Yii2;
use Codeception\Stub;
use Codeception\TestInterface;
use craft\config\DbConfig;
use craft\db\Connection;
use craft\elements\User;
use craft\helpers\App;
use craft\services\Security;
use yii\base\Application;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Module;

/**
 * Craft module for codeception
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since 3.0
 */
class Craft extends Yii2
{
    // Setup work
    // =========================================================================

    /**
     * Application config file must be set.
     * @var array
     */
    protected $addedConfig = [
        'plugins' => [],
        'migrations' => [],
        'modules' => [],
        'setupDb' => null,
    ];

    /**
     * For expecting events code
     * @var array
     */
    protected $triggeredEvents = [];
    protected $requiredEvents = [];

    /**
     * The DB connection that is used to apply migrations and db setups
     * @var Connection $dbConnection
     */
    private $dbConnection;

    /**
     * A static version of the config for use on the tests/_craft/config/test.php file
     * @var array
     */
    public static $testConfig;

    /**
     * Craft constructor.
     * We need to merge the config settings here as this is the earliest point in the instance's existance.
     * Doing it in _initialize() wont work as the config variables have already been added.
     *
     * @param ModuleContainer $moduleContainer
     * @param null $config
     */
    public function __construct(ModuleContainer $moduleContainer, $config = null)
    {
        // Merge our config with Yii'2 config.
        $this->config = array_merge(parent::_getConfig(), $this->addedConfig);

        parent::__construct($moduleContainer, $config);
    }

    /**
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public function _initialize()
    {
        self::$testConfig = $this->_getConfig();
        $this->setupDb();

        parent::_initialize();

    }

    /**
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public function setupDb()
    {
        ob_start();
        // Create a Craft::$app object
        TestSetup::warmCraft();

        $this->dbConnection = \Craft::createObject(App::dbConfig(self::createDbConfig()));

        \Craft::$app->set('db', $this->dbConnection);

        $testSetup = self::createTestSetup($this->dbConnection);

        if ($this->_getConfig('dbSetup')['clean'] === true) {
            $testSetup->clenseDb();
        }

        if ($this->_getConfig('dbSetup')['setupCraft'] === true) {
            $testSetup->setupCraftDb();
        }

        if ($this->_getConfig('dbSetup')['setupMigrations'] === true) {
            foreach ($this->_getConfig('migrations') as $migration) {
                $testSetup->validateAndApplyMigration($migration['class'], $migration['params']);
            }
        }

        foreach ($this->_getConfig('plugins') as $plugin) {
            $this->installPlugin($plugin);
        }

        // Dont output anything or we get header's already sent exception
        ob_end_clean();
        TestSetup::tearDownCraft();
    }

    /**
     * @param array $plugin
     * @throws InvalidConfigException
     * @throws \Throwable
     * @throws \craft\errors\InvalidPluginException
     */
    public function installPlugin(array $plugin) {
        if (!\Craft::$app->getPlugins()->installPlugin($plugin['handle'])) {
            throw new InvalidConfigException('Invalid plugin handle: '. $plugin['handle'] .'');
        }
    }

    /**
     * @param TestInterface $test
     * @throws \yii\base\InvalidConfigException
     */
    public function _before(TestInterface $test)
    {
        parent::_before($test);

        App::maxPowerCaptain();
        /**
         * TODO:
         * There is a potential 'bug'/hampering feature with the Yii2 Codeception module.
         * DB connections initialized through the configFile param (see https://codeception.com/docs/modules/Yii2)
         * Are not captured by the Yii2Connector\ConnectionWatcher and Yii2Connector\TransactionForcer i.e. all DB interacitons done through
         * Craft::$app->getDb() are not stored and roll'd back in transacitons.
         *
         * This is probably because the starting of the app (triggered by $this->client->startApp()) is done BEFORE the
         * DB event listeners are registered. Moving the order of these listeners to the top of the _before function means the conneciton
         * is registered.
         *
         * What i need to investigate is whether iam doing something wrong in the src/tests/_craft/config/test.php or if this is PR 'worthy'
         * For now: Remounting the DB object using Craft::$app->set() after the event listeners are called works perfectly fine.
         */
        $db = \Craft::createObject(
            \craft\helpers\App::dbConfig(self::createDbConfig())
        );

        \Craft::$app->set('db', $db);
    }

    /**
     * @param TestInterface $test
     * @throws \yii\db\Exception
     */
    public function _after(TestInterface $test)
    {
        // https://github.com/yiisoft/yii2/issues/11633 || The (possibly) MyISAM {{%searchindex}} table doesnt support transactions.
        // So we manually delete any rows in there except if the element id is 1 (The user added when creating the DB)
        parent::_after($test);

        \Craft::$app->getDb()->createCommand()
            ->delete('{{%searchindex}}', 'elementId != 1')
            ->execute();
    }

    /**
     * Gets any custom test setup config based on variables in this class.
     * The array returned in here gets merged with what is returned in tests/_craft/config/test.php
     *
     * @return array
     */
    public static function getTestSetupConfig() : array
    {
        $returnArray = [];
        $config = self::$testConfig;

        // Add the modules to the config similar to how its done here: https://github.com/craftcms/craft/blob/master/config/app.php
        if (isset($config['modules']) && is_array($config['modules'])) {
            foreach ($config['modules'] as $module) {
                $returnArray['modules'][$module['handle']] = $module['class'];
                $returnArray['bootstrap'][] = $module['handle'];
            }
        }

        return $returnArray;
    }

    // Helper and to-be-directly used in test methods.
    // =========================================================================

    /**
     * Ensure that an event is trigered by the $callback() function.
     *
     *
     * @param string $class
     * @param string $eventName
     * @param $callback
     */
    public function expectEvent(string $class, string $eventName, $callback)
    {
        // Add this event.
        $requiredEvent = null;

        // Listen to this event and log it.
        Event::on($class, $eventName, function () use (&$requiredEvent) {
            $requiredEvent = true;
        });

        $callback();

        $this->assertTrue($requiredEvent, 'Asserting that an event is triggered');
    }

    /**
     * @param Module $module
     * @param string $component
     * @param $methods
     * @throws InvalidConfigException
     */
    public function mockMethods(Module $module, string $component, array $methods = [], array $constructParams = [])
    {
        $componentInstance = $module->get($component);

        $module->set($component, Stub::construct(get_class($componentInstance), [$constructParams], $methods));
    }

    public function mockCraftMethods(string $component, array $methods = [], array $constructParams = [])
    {
        return $this->mockMethods(\Craft::$app, $component, $methods, $constructParams);
    }

    // Factories
    // =========================================================================

    /**
     * Creates a DB config according to the loaded .ENV variables.
     * @return DbConfig
     */
    public static function createDbConfig() : DbConfig
    {
        return new DbConfig([
            'password' => getenv('TEST_DB_PASS'),
            'user' => getenv('TEST_DB_USER'),
            'database' => getenv('TEST_DB_NAME'),
            'tablePrefix' => getenv('TEST_DB_TABLE_PREFIX'),
            'driver' => getenv('TEST_DB_DRIVER'),
            'port' => getenv('TEST_DB_PORT'),
            'schema' => getenv('TEST_DB_SCHEMA'),
            'server' => getenv('TEST_DB_SERVER'),
        ]);
    }

    /**
     * @param Connection $connection
     * @return TestSetup
     */
    public static function createTestSetup(Connection $connection) : TestSetup
    {
        return new TestSetup($connection);
    }
}
