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

    public function exists()
    {
        return $this->connection->getSchemaManager()->tablesExist($this->table->getName());
    }

    public function create()
    {
        if (!$this->exists()) {
            $this->connection->getSchemaManager()->createTable($this->table);
            return true;
        }
        return false;
    }

    public function drop()
    {
        if ($this->exists()) {
            $this->connection->getSchemaManager()->dropTable($this->table);
            return true;
        }
        return false;
    }

    public function glob($migdir)
    {
        $migfiles = glob($migdir . '/*.{sql,php}', GLOB_BRACE);
        return array_combine(array_map('basename', $migfiles), array_map('file_get_contents', $migfiles));
    }

    public function fetch()
    {
        if (!$this->exists()) {
            return array();
        }
        return $this->connection->executeQuery("SELECT * FROM " . $this->table->getName())->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_UNIQUE);
    }

    public function apply($version, $content)
    {
        $ext = pathinfo($version, PATHINFO_EXTENSION);
        switch ($ext) {
            default:
                throw new \InvalidArgumentException("'$ext' is not supported.");

            case 'sql':
                $this->connection->exec($content);
                break;
            case 'php':
                $connection = $this->connection;
                $return = eval("?>$content;");
                if ($return instanceof \Closure) {
                    $return = call_user_func($return, $connection);
                }
                foreach ((array) $return as $sql) {
                    if ($sql) {
                        $this->connection->exec($sql);
                    }
                }
                break;
        }
        $this->attach($version);
    }

    public function attach($version)
    {
        $versions = array_map(function ($version) {
            return '(' . Utility::quote($this->connection, $version) . ',NOW())';
        }, (array) $version);
        return $this->connection->executeUpdate("INSERT INTO " . $this->table->getName() . " VALUES " . implode(',', $versions));
    }

    public function detach($version)
    {
        $versions = array_map(function ($version) {
            return Utility::quote($this->connection, $version);
        }, (array) $version);
        return $this->connection->executeUpdate("DELETE FROM " . $this->table->getName() . " WHERE version IN (" . implode(',', $versions) . ")");
    }
}
