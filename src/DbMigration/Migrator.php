<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;

class Migrator
{
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
        $platform = $old->getDatabasePlatform();
        $fromSchema = $old->getSchemaManager()->createSchema();
        $toSchema = $new->getSchemaManager()->createSchema();
        $diff = Comparator::compareSchemas($fromSchema, $toSchema);

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

        return $diff->toSql($platform);
    }

    /**
     * diff table, get DML
     *
     * @param Connection $old
     * @param Connection $new
     * @param string $table
     * @param array $wheres
     * @param array $ignores
     * @throws DBALException
     * @return array
     */
    static public function getDML($old, $new, $table, array $wheres = array(), array $ignores = array())
    {
        /** @var Schema[] $schemaCache */
        static $schemaCache = array();

        // cache $schema
        $oldid = spl_object_hash($old);
        if (!isset($schemaCache[$oldid])) {
            $schemaCache[$oldid] = $old->getSchemaManager()->createSchema();
        }
        $newid = spl_object_hash($new);
        if (!isset($schemaCache[$newid])) {
            $schemaCache[$newid] = $new->getSchemaManager()->createSchema();
        }

        // get schema
        $oldSchema = $schemaCache[$oldid];
        $newSchema = $schemaCache[$newid];

        // result dmls
        $dmls = array();

        // scanner objects
        $oldScanner = new TableScanner($old, $oldSchema->getTable($table), $wheres, $ignores);
        $newScanner = new TableScanner($new, $newSchema->getTable($table), $wheres, $ignores);

        // check different column definitation
        if (!$oldScanner->equals($newScanner)) {
            throw new MigrationException("has different definition between schema.");
        }

        // primary key tuples
        $oldTuples = $oldScanner->getPrimaryRows();
        $newTuples = $newScanner->getPrimaryRows();

        // DELETE if old only
        if ($tuples = array_diff_key($oldTuples, $newTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getDeleteSql($tuples, $oldScanner));
        }

        // UPDATE if common
        if ($tuples = array_intersect_key($oldTuples, $newTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getUpdateSql($tuples, $newScanner));
        }

        // INSERT if new only
        if ($tuples = array_diff_key($newTuples, $oldTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getInsertSql($tuples, $newScanner));
        }

        return $dmls;
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
