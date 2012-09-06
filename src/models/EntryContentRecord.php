<?php
namespace Blocks;

/**
 *
 */
class EntryContentRecord extends BaseRecord
{
	protected $section;

	/**
	 * Constructor
	 *
	 * @param SectionRecord $section
	 */
	public function __construct($section = null)
	{
		if ($section && $section instanceof SectionRecord)
			$this->section = $section;

		parent::__construct(null);
	}

	public function getTableName()
	{
		if (isset($this->section))
			return static::getTableNameForSection($this->section);
		else
			throw new Exception(Blocks::t('Cannot get the table name if a section hasn’t been defined.'));
	}

	/**
	 * Returns the table name for an entry content table.
	 * (lame that this can't also be called getTableName() -- see https://bugs.php.net/bug.php?id=40837)
	 *
	 * @static
	 * @param SectionRecord $section
	 * @return string
	 */
	public static function getTableNameForSection($section)
	{
		return 'entrycontent_'.$section->handle;
	}

	protected function defineAttributes()
	{
		return array(
			'language' => AttributeType::Language,
			'title'    => AttributeType::Varchar,
		);
	}

	protected function defineRelations()
	{
		return array(
			'entry' => array(static::BELONGS_TO, 'EntryRecord', 'required' => true),
		);
	}

	protected function defineIndexes()
	{
		return array(
			array('columns' => array('entryId', 'language'), 'unique' => true),
		);
	}

	/* Prevent table creation/deletion unless $section has been set */

	public function createTable()
	{
		if (isset($this->section))
			parent::createTable();
	}

	public function dropTable()
	{
		if (isset($this->section))
			parent::dropTable();
	}

	public function addForeignKeys()
	{
		if (isset($this->section))
			parent::addForeignKeys();
	}

	public function dropForeignKeys()
	{
		if (isset($this->section))
			parent::dropForeignKeys();
	}
}
