<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;

class MigrationTable
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var Table
     */
    private $table;

    public function __construct(Connection $connection, $tableName)
    {
        $this->connection = $connection;

        $this->table = new Table($tableName, array(
            new Column('version', \Doctrine\DBAL\Types\Type::getType('string')),
            new Column('apply_at', \Doctrine\DBAL\Types\Type::getType('datetime')),
        ), array(
            new Index('PRIMARY', array('version'), true, true),
        ));
    }

    public function create()
    {
        if (!$this->connection->getSchemaManager()->tablesExist($this->table->getName())) {
            $this->connection->getSchemaManager()->createTable($this->table);
            return true;
        }
        return false;
    }

    public function drop()
    {
        if ($this->connection->getSchemaManager()->tablesExist($this->table->getName())) {
            $this->connection->getSchemaManager()->dropTable($this->table);
            return true;
        }
        return false;
    }

    public function glob($migdir)
    {
        $migfiles = glob($migdir . '/*.sql');
        return array_combine(array_map('basename', $migfiles), array_map('file_get_contents', $migfiles));
    }

    public function fetch()
    {
        return $this->connection->executeQuery("SELECT * FROM " . $this->table->getName())->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_UNIQUE);
    }

    public function attach($version)
    {
        $versions = array_map(function ($version) {
            return '(' . $this->connection->quote($version) . ',NOW())';
        }, (array) $version);
        return $this->connection->executeUpdate("INSERT INTO " . $this->table->getName() . " VALUES " . implode(',', $versions));
    }

    public function detach($version)
    {
        $versions = array_map(function ($version) {
            return $this->connection->quote($version);
        }, (array) $version);
        return $this->connection->executeUpdate("DELETE FROM " . $this->table->getName() . " WHERE version IN (" . implode(',', $versions) . ")");
    }
}
