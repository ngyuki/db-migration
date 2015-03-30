<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class Generator
{

    /**
     * diff schema, get DDL
     *
     * @param Connection $old
     * @param Connection $new
     * @param array $includes
     * @param array $excludes
     * @return array
     */
    static public function getDDL($old, $new, $includes = array(), $excludes = array())
    {
        $platform = $old->getDatabasePlatform();
        $fromSchema = $old->getSchemaManager()->createSchema();
        $toSchema = $new->getSchemaManager()->createSchema();
        $diff = Comparator::compareSchemas($fromSchema, $toSchema);
        
        // @codeCoverageIgnoreStart
        if (method_exists($diff, 'toFilterSql')) {
            return $diff->toFilterSql($platform, $includes, $excludes);
        } else {
            return $diff->toSql($platform);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * diff table, get DML
     *
     * @param Connection $old
     * @param Connection $new
     * @param array $tables
     * @throws DBALException
     * @return array
     */
    static public function getDML($old, $new, $tables)
    {
        static $schemaCache = array();
        
        // cache $schema
        $oldid = spl_object_hash($old);
        if (! isset($schemaCache[$oldid])) {
            $schemaCache[$oldid] = $old->getSchemaManager()->createSchema();
        }
        $newid = spl_object_hash($new);
        if (! isset($schemaCache[$newid])) {
            $schemaCache[$newid] = $new->getSchemaManager()->createSchema();
        }
        
        // get schema
        $oldSchema = $schemaCache[$oldid];
        $newSchema = $schemaCache[$newid];
        
        // result dmls
        $dmls = array();
        
        // loop for table
        foreach ($tables as $cond => $table_pair) {
            // scanner objects
            $oldScanner = new TableScanner($old, $oldSchema->getTable($table_pair[0]), $cond);
            $newScanner = new TableScanner($new, $newSchema->getTable($table_pair[1]), $cond);
            
            // check different column definitation
            if (! $oldScanner->equals($newScanner)) {
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
        }
        
        return $dmls;
    }
}
