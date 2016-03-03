<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;

class TableScanner
{
    /**
     * count of 1 page fetching
     *
     * @var int
     */
    public static $pageCount = 10000;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $conn;

    /**
     * @var \Doctrine\DBAL\Schema\Table
     */
    private $table;

    /**
     * @var string
     */
    private $quotedName;

    /**
     * @var string
     */
    private $filterCondition;

    /**
     * @var array
     */
    private $primaryKeys;

    /**
     * @var array
     */
    private $flippedPrimaryKeys;

    /**
     * @var \Doctrine\DBAL\Schema\Column[]
     */
    private $columns;

    /**
     * @var string
     */
    private $primaryKeyString;

    /**
     * @var string
     */
    private $columnString;

    /**
     * constructor
     *
     * @param Connection $conn
     * @param Table $table
     * @param array $filterCondition
     */
    public function __construct(Connection $conn, Table $table, $filterCondition)
    {
        if (!$table->hasPrimaryKey()) {
            throw new MigrationException("has no primary key.");
        }
        
        // set property from argument
        $this->conn = $conn;
        $this->table = $table;
        
        // set property from property
        $this->quotedName = $conn->quoteIdentifier($this->table->getName());
        $this->primaryKeys = $this->table->getPrimaryKeyColumns();
        $this->flippedPrimaryKeys = array_flip($this->primaryKeys);
        
        // column to array(ColumnNanme => Column)
        $this->columns = array();
        foreach ($table->getColumns() as $column) {
            $this->columns[$column->getName()] = $column;
        }
        
        // to string
        $this->primaryKeyString = implode(', ', $this->quoteArray(true, $this->primaryKeys));
        $this->columnString = implode(', ', $this->quoteArray(true, array_keys($this->columns)));
        
        // parse condition
        $platform = $conn->getDatabasePlatform();
        $this->filterCondition = $this->parseCondition($filterCondition, $platform->getIdentifierQuoteCharacter());
    }

    /**
     * compare another instance
     *
     * @param TableScanner $that
     * @return boolean
     */
    public function equals(TableScanner $that)
    {
        // compare primary key name
        if ($this->primaryKeyString !== $that->primaryKeyString) {
            return false;
        }
        
        // compare column name
        if (array_fill_keys(array_keys($this->columns), '') != array_fill_keys(array_keys($that->columns), '')) {
            return false;
        }
        
        // compare column attribute
        foreach ($this->columns as $name => $thisColumn) {
            $thatColumn = $that->columns[$name];
            
            if (strval($thisColumn->getType()) !== strval($thatColumn->getType())) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * get DELETE sql from primary tuples
     *
     * @param array $tuples
     * @param TableScanner $that
     * @return array
     */
    public function getDeleteSql(array $tuples, TableScanner $that)
    {
        $sqls = array();
        for ($page = 0; true; $page++) {
            $oldrows = $that->getRecordFromPrimaryKeys($tuples, $page);
            
            // loop for limited rows
            $count = count($sqls);
            while (($oldrow = $oldrows->fetch()) !== false) {
                // to comment
                $comment = $this->commentize($oldrow);
                
                // to WHERE string
                $wheres = array_intersect_key($oldrow, $this->flippedPrimaryKeys);
                $whereString = $this->buildWhere($wheres);
                
                // to SQL
                $sqls[] = "$comment\nDELETE FROM $this->quotedName WHERE $whereString";
            }
            
            // no more rows
            if ($count === count($sqls)) {
                break;
            }
        }
        
        return $sqls;
    }

    /**
     * get INSERT sql from primary tuples
     *
     * @param array $tuples
     * @param TableScanner $that
     * @return array
     */
    public function getInsertSql(array $tuples, TableScanner $that)
    {
        $sqls = array();
        for ($page = 0; true; $page++) {
            $newrows = $that->getRecordFromPrimaryKeys($tuples, $page);
            
            // loop for limited rows
            $count = count($sqls);
            while (($newrow = $newrows->fetch()) !== false) {
                // to VALUES string
                $valueString = implode(', ', $this->quoteArray(false, $newrow));
                
                // to SQL
                $sqls[] = "INSERT INTO $this->quotedName ($this->columnString) VALUES\n  ($valueString)";
            }
            
            // no more rows
            if ($count === count($sqls)) {
                break;
            }
        }
        
        return $sqls;
    }

    /**
     * get UPDATE sql from primary tuples
     *
     * @param array $tuples
     * @param TableScanner $that
     * @return array
     */
    public function getUpdateSql(array $tuples, TableScanner $that)
    {
        $sqls = array();
        for ($page = 0; true; $page++) {
            $oldrows = $this->getRecordFromPrimaryKeys($tuples, $page);
            $newrows = $that->getRecordFromPrimaryKeys($tuples, $page);
            
            // loop for limited rows
            $count = count($sqls);
            while (($oldrow = $oldrows->fetch()) !== false && ($newrow = $newrows->fetch()) !== false) {
                // no diff row
                if (!($deltas = array_diff_assoc($newrow, $oldrow))) {
                    continue;
                }
                
                // to comment
                $comments = array_intersect_key($oldrow, $deltas);
                $comment = $this->commentize($comments);
                
                // to VALUES string
                $valueString = implode(",\n  ", $this->joinKeyValue($deltas));
                
                // to WHERE string
                $wheres = array_intersect_key($newrow, $this->flippedPrimaryKeys);
                $whereString = $this->buildWhere($wheres);
                
                // to SQL
                $sqls[] = "$comment\nUPDATE $this->quotedName SET\n  $valueString\nWHERE $whereString";
            }
            
            // no more rows
            if ($count === count($sqls)) {
                break;
            }
        }
        
        return $sqls;
    }

    /**
     * get primary key tuples
     *
     * @return array
     */
    public function getPrimaryRows()
    {
        // fetch primary values
        $sql = "
            SELECT   {$this->primaryKeyString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition}
            ORDER BY {$this->primaryKeyString}
        ";
        
        $result = array();
        foreach ($this->conn->query($sql) as $row) {
            $id = implode("\t", $row);
            $result[$id] = $row;
        }
        return $result;
    }

    /**
     * get record from primary key tuples
     *
     * @param array $tuples
     * @param int $page
     * @return \Doctrine\DBAL\Driver\Statement
     */
    private function getRecordFromPrimaryKeys(array $tuples, $page = null)
    {
        $stuples = $tuples;
        if ($page !== null) {
            $stuples = array_slice($tuples, intval($page) * self::$pageCount, self::$pageCount);
        }
        
        // prepare sql of primary key record
        $tuplesString = $this->buildWhere($stuples);
        $sql = "
            SELECT   {$this->columnString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition} AND ($tuplesString)
            ORDER BY {$this->primaryKeyString}
        ";
        
        return $this->conn->query($sql);
    }

    private function parseCondition($conds, $icharactor)
    {
        $identifier = "$icharactor?([_a-z][_a-z0-9]*)$icharactor?";
        $tableName = $this->table->getName();

        $wheres = array();
        foreach ((array) $conds as $cond) {
            if (preg_match_all("/($identifier\\.)?$identifier/i", $cond, $matches)) {
                $result = array();
                foreach ($matches[0] as $i => $dummy) {
                    $key = $matches[2][$i];
                    $val = $matches[3][$i];

                    $key = $key === '' ? $tableName : $key;

                    $result[$key][] = $val;
                }

                if (array_key_exists($tableName, $result)) {
                    foreach ($this->table->getColumns() as $columnName => $column) {
                        if (in_array($columnName, $result[$tableName])) {
                            $wheres[] = $cond;
                        }
                    }
                }
            }
        }

        if ($wheres) {
            return implode(' AND ', $wheres);
        }
        
        return 'TRUE';
    }

    /**
     * quote array elements
     *
     * @param bool $is_identifier
     * @param array $array
     * @return array
     */
    private function quoteArray($is_identifier, array $array)
    {
        // for under php 5.4
        $conn = $this->conn;
        
        // quote
        return array_map(function ($val) use ($is_identifier, $conn) {
            return $is_identifier ? $conn->quoteIdentifier($val) : $conn->quote($val);
        }, $array);
    }

    /**
     * array to comment string
     *
     * @param array $data
     * @param int $width
     * @return string
     */
    private function commentize(array $data, $width = 80)
    {
        // shorten value
        $comment = var_export(array_map(function ($val) use ($width) {
            if (is_string($val)) {
                return mb_strimwidth($val, 0, $width, '...');
            }
            return $val;
        }, $data), true);
        
        // adjust and sanitize
        $comment = preg_replace('/^array \(/u', ' current record:', $comment);
        $comment = preg_replace('/\)$/u', '', $comment);
        $comment = str_replace('*/', '* /', $comment);
        
        return "/*{$comment}*/";
    }

    /**
     * join key and value of array
     *
     * @param array $array
     * @param string $separator
     * @return array
     */
    private function joinKeyValue(array $array, $separator = ' = ')
    {
        $keys = $this->quoteArray(true, array_keys($array));
        $vals = $this->quoteArray(false, array_values($array));
        
        return array_map(function ($key, $val) use ($separator) {
            return "{$key}{$separator}{$val}";
        }, $keys, $vals);
    }

    /**
     * build quoted where string from array
     *
     * @param array $whereArray
     * @return string
     */
    private function buildWhere(array $whereArray)
    {
        if (count($whereArray) === 0) {
            return "FALSE";
        }
        
        if (count($whereArray, COUNT_RECURSIVE) === count($whereArray)) {
            $and = $this->joinKeyValue($whereArray);
            return '(' . implode(' AND ', $and) . ')';
        }
        else {
            $or = array();
            foreach ($whereArray as $values) {
                $or[] = $this->buildWhere($values);
            }
            return '(' . implode(' OR ', $or) . ')';
        }
    }
}
