<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;

class Migrator
{
    /**
     * @var Schema[]
     */
    private static $schemas;

    /**
     * diff schema, get DDL
     *
     * @param Connection $old
     * @param Connection $new
     * @param array $includes
     * @param array $excludes
     * @param bool $noview
     * @return array
     */
    static public function getDDL($old, $new, $includes = array(), $excludes = array(), $noview = false)
    {
        $diff = Comparator::compareSchemas(self::getSchema($old), self::getSchema($new));

        foreach ($diff->newTables as $name => $table) {
            $filterdResult = self::filterTable($name, $includes, $excludes);
            if ($filterdResult > 0) {
                unset($diff->newTables[$name]);
            }
        }

        foreach ($diff->changedTables as $name => $table) {
            $filterdResult = self::filterTable($name, $includes, $excludes);
            if ($filterdResult > 0) {
                unset($diff->changedTables[$name]);
            }
        }

        foreach ($diff->removedTables as $name => $table) {
            $filterdResult = self::filterTable($name, $includes, $excludes);
            if ($filterdResult > 0) {
                unset($diff->removedTables[$name]);
            }
        }

        if ($noview) {
            $diff->newViews = array();
            $diff->changedViews = array();
            $diff->removedViews = array();
        }

        return $diff->toSql($old->getDatabasePlatform());
    }

    /**
     * diff table, get DML
     *
     * @param Connection $old
     * @param Connection $new
     * @param string $table
     * @param array $wheres
     * @param array $ignores
     * @param array $dmltypes
     * @throws DBALException
     * @return array
     */
    static public function getDML($old, $new, $table, array $wheres = array(), array $ignores = array(), $dmltypes = array())
    {
        // result dmls
        $dmls = array();

        // scanner objects
        $oldSchema = self::getSchema($old);
        $newSchema = self::getSchema($new);
        $oldScanner = new TableScanner($old, $oldSchema->getTable($table), $wheres, $ignores);
        $newScanner = new TableScanner($new, $newSchema->getTable($table), $wheres, $ignores);

        // check different column definitation
        if (!$oldScanner->equals($newScanner)) {
            throw new MigrationException("has different definition between schema.");
        }

        // primary key tuples
        $oldTuples = $oldScanner->getPrimaryRows();
        $newTuples = $newScanner->getPrimaryRows();

        $defaulttypes = array(
            'insert' => true,
            'delete' => true,
            'update' => true,
        );
        $dmltypes += $defaulttypes;

        // DELETE if old only
        if ($dmltypes['delete'] && $tuples = array_diff_key($oldTuples, $newTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getDeleteSql($tuples, $oldScanner));
        }

        // UPDATE if common
        if ($dmltypes['update'] && $tuples = array_intersect_key($oldTuples, $newTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getUpdateSql($tuples, $newScanner));
        }

        // INSERT if new only
        if ($dmltypes['insert'] && $tuples = array_diff_key($newTuples, $oldTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getInsertSql($tuples, $newScanner));
        }

        return $dmls;
    }

    static public function setSchema(Connection $connection, Schema $schema = null)
    {
        $id = spl_object_hash($connection->getWrappedConnection());
        self::$schemas[$id] = $schema;
    }

    static public function getSchema(Connection $connection)
    {
        $id = spl_object_hash($connection->getWrappedConnection());
        if (!isset(self::$schemas[$id])) {
            self::$schemas[$id] = $connection->getSchemaManager()->createSchema();
        }
        return self::$schemas[$id];
    }

    static public function filterTable($tablename, $includes, $excludes)
    {
        // filter from includes
        $flag = count($includes) > 0;
        foreach ($includes as $include) {
            foreach (array_map('trim', explode(',', $include)) as $regex) {
                if (preg_match("@$regex@i", $tablename)) {
                    $flag = false;
                    break;
                }
            }
        }
        if ($flag) {
            return 1;
        }

        // filter from excludes
        foreach ($excludes as $exclude) {
            foreach (array_map('trim', explode(',', $exclude)) as $regex) {
                if (preg_match("@$regex@i", $tablename)) {
                    return 2;
                }
            }
        }

        return 0;
    }
}
