<?php
namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Symfony\Component\Yaml\Yaml;

class Transporter
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var AbstractPlatform
     */
    private $platform;

    /**
     * @var Schema
     */
    private $schema;

    /**
     * @var array
     */
    private $encodings = array();

    /**
     * @var array
     */
    private $defaultColumnAttributes = array(
        'length'           => null,
        'precision'        => 10,
        'scale'            => 0,
        'fixed'            => false,
        'autoincrement'    => false,
        'columnDefinition' => null,
    );

    /**
     * @var array
     */
    private $defaultIndexAttributes = array(
        'primary' => false,
        'flag'    => array(),
        'option'  => array(),
    );

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->platform = $connection->getDatabasePlatform();
        $this->schema = $connection->getSchemaManager()->createSchema();
    }

    public function setEncoding($ext, $encoding)
    {
        $this->encodings[$ext] = $encoding;
    }

    public function exportDDL($filename)
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        // SQL is special
        if ($ext === 'sql') {
            $creates = $alters = array();
            foreach ($this->schema->getTables() as $table) {
                $sqls = $this->platform->getCreateTableSQL($table, AbstractPlatform::CREATE_INDEXES | AbstractPlatform::CREATE_FOREIGNKEYS);
                $creates[] = \SqlFormatter::format(array_shift($sqls), false);
                $alters = array_merge($alters, $sqls);
            }
            $content = implode(";\n", array_merge($creates, $alters)) . ";\n";
        }
        else {
            // generate schema array
            $schemaArray = array(
                'platform' => $this->platform->getName(),
                'table'    => array(),
            );
            foreach ($this->schema->getTables() as $table) {
                $tarray = $this->tableToArray($table);
                $schemaArray['table'][$table->getName()] = $tarray;
            }

            // by Data Description Language
            switch ($ext) {
                default:
                    throw new \DomainException("'$ext' is not supported.");
                case 'php':
                    $content = "<?php return\n" . var_export($schemaArray, true) . ";\n";
                    break;
                case 'json':
                    $content = json_encode($schemaArray, self::getJsonOption()) . "\n";
                    break;
                case 'yml':
                case 'yaml':
                    $content = Yaml::dump($schemaArray, 4, 4);
                    break;
            }
        }

        self::file_put_contents($filename, $content);
        return $content;
    }

    public function exportDML($filename, $filterCondition, $ignoreColumn)
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        // create TableScanner
        $tablename = basename($filename, ".$ext");
        $table = $this->schema->getTable($tablename);
        $scanner = new TableScanner($this->connection, $table, $filterCondition, $ignoreColumn);

        // for too many records
        $current = $scanner->switchBufferedQuery(false);

        switch ($ext) {
            default:
                throw new \DomainException("'$ext' is not supported.");
            case 'sql':
                $qtable = $this->connection->quoteIdentifier($tablename);
                $result = array();
                foreach ($scanner->getAllRows() as $row) {
                    $row = $scanner->fillDefaultValue($row);
                    $columns = implode(', ', array_map(array($this->connection, 'quoteIdentifier'), array_keys($row)));
                    $values = implode(', ', array_map(array($this->connection, 'quote'), $row));
                    $result[] = "INSERT INTO $qtable ($columns) VALUES ($values);";
                }
                $result = implode("\n", $result) . "\n";
                break;
            case 'php':
                $result = array();
                foreach ($scanner->getAllRows() as $row) {
                    $result[] = var_export($scanner->fillDefaultValue($row), true);
                }
                $result = "<?php return array(\n" . implode(",\n", $result) . "\n);\n";
                break;
            case 'json':
                $result = array();
                foreach ($scanner->getAllRows() as $row) {
                    $result[] = json_encode($scanner->fillDefaultValue($row), self::getJsonOption());
                }
                $result = "[\n" . implode(",\n", $result) . "\n]\n";
                break;
            case 'yml':
            case 'yaml':
                $result = array();
                foreach ($scanner->getAllRows() as $row) {
                    $result[] = Yaml::dump(array($scanner->fillDefaultValue($row)), 99, 4);
                }
                $result = implode("", $result);
                break;
        }

        // restore
        $scanner->switchBufferedQuery($current);

        if (isset($this->encodings[$ext]) && $this->encodings[$ext] != mb_internal_encoding()) {
            $result = mb_convert_encoding($result, $this->encodings[$ext]);
        }

        self::file_put_contents($filename, $result);
        return $result;
    }

    public function importDDL($filename)
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        switch ($ext) {
            default:
                throw new \DomainException("'$ext' is not supported.");
            case 'sql':
                $contents = file_get_contents($filename);
                $this->connection->exec($contents);
                return $this->explodeSql($contents);
            case 'php':
                $schemaArray = require $filename;
                break;
            case 'json':
                $schemaArray = json_decode(file_get_contents($filename), true);
                break;
            case 'yml':
            case 'yaml':
                $schemaArray = Yaml::parse(file_get_contents($filename));
                break;
        }

        if (isset($schemaArray['platform']) && $schemaArray['platform']) {
            if ($schemaArray['platform'] !== $this->platform->getName()) {
                throw new \RuntimeException('platform is different.');
            }
        }

        $creates = $alters = array();
        foreach ($schemaArray['table'] as $name => $tarray) {
            $table = $this->tableFromArray($name, $tarray);
            $sqls = $this->platform->getCreateTableSQL($table, AbstractPlatform::CREATE_INDEXES | AbstractPlatform::CREATE_FOREIGNKEYS);
            $creates[] = array_shift($sqls);
            $alters = array_merge($alters, $sqls);
        }

        $sqls = array_merge($creates, $alters);
        foreach ($sqls as $sql) {
            $this->connection->exec($sql);
        }

        return $sqls;
    }

    public function importDML($filename)
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $to_encoding = mb_internal_encoding();
        $encoding = null;
        if (isset($this->encodings[$ext]) && $this->encodings[$ext] != $to_encoding) {
            $encoding = $this->encodings[$ext];
        }

        switch ($ext) {
            default:
                throw new \DomainException("'$ext' is not supported.");
            case 'sql':
                $contents = file_get_contents($filename);
                self::mb_convert_variables($to_encoding, $encoding, $contents);
                $this->connection->exec($contents);
                return $this->explodeSql($contents);
            case 'php':
                $rows = include($filename);
                self::mb_convert_variables($to_encoding, $encoding, $rows);
                break;
            case 'json':
                $contents = file_get_contents($filename);
                self::mb_convert_variables($to_encoding, $encoding, $contents);
                $rows = json_decode($contents, true);
                break;
            case 'yml':
            case 'yaml':
                $contents = file_get_contents($filename);
                self::mb_convert_variables($to_encoding, $encoding, $contents);
                $rows = Yaml::parse($contents);
                break;
        }

        $tablename = basename($filename, ".$ext");
        $qtable = $this->connection->quoteIdentifier($tablename);

        foreach ($rows as $row) {
            $this->connection->insert($qtable, $row);
        }

        return $rows;
    }

    private function tableToArray(Table $table)
    {
        // entry keys
        $entry = array(
            'column'  => array(),
            'index'   => array(),
            'foreign' => array(),
            'option'  => $table->getOptions(),
        );

        // add columns
        foreach ($table->getColumns() as $column) {
            $array = array(
                'type'                => $column->getType()->getName(),
                'default'             => $column->getDefault(),
                'notnull'             => $column->getNotnull(),
                'length'              => $column->getLength(),
                'precision'           => $column->getPrecision(),
                'scale'               => $column->getScale(),
                'fixed'               => $column->getFixed(),
                'unsigned'            => $column->getUnsigned(),
                'autoincrement'       => $column->getAutoincrement(),
                'columnDefinition'    => $column->getColumnDefinition(),
                'comment'             => $column->getComment(),
                'platformOptions'     => $column->getPlatformOptions(),
                'customSchemaOptions' => $column->getCustomSchemaOptions(),
            );
            $array = self::array_diff_assoc($array, $this->defaultColumnAttributes);
            if (!in_array($array['type'], array('smallint', 'integer', 'bigint', 'decimal', 'float'), true)) {
                unset($array['unsigned']);
            }
            $entry['column'][$column->getName()] = $array;
        }

        // add indexes
        foreach ($table->getIndexes() as $index) {
            $array = array(
                'column'  => $index->getColumns(),
                'primary' => $index->isPrimary(),
                'unique'  => $index->isUnique(),
                'flag'    => $index->getFlags(),
                'option'  => $index->getOptions(),
            );
            $array = self::array_diff_assoc($array, $this->defaultIndexAttributes);
            $entry['index'][$index->getName()] = $array;
        }

        // add foreign keys
        foreach ($table->getForeignKeys() as $fkey) {
            $entry['foreign'][$fkey->getName()] = array(
                'table'  => $fkey->getForeignTableName(),
                'column' => array_combine($fkey->getLocalColumns(), $fkey->getForeignColumns()),
                'option' => $fkey->getOptions(),
            );
        }

        return $entry;
    }

    private function tableFromArray($name, array $array)
    {
        // base table
        $table = new Table($name, array(), array(), array(), 0, $array['option']);

        // add columns
        foreach ($array['column'] as $name => $column) {
            $type = $column['type'];
            unset($column['type']);
            $column += $this->defaultColumnAttributes;
            $table->addColumn($name, $type, $column);
        }

        // add indexes
        foreach ($array['index'] as $name => $index) {
            $index += $this->defaultIndexAttributes;
            if ($index['primary']) {
                $table->setPrimaryKey($index['column'], $name);
            }
            else if ($index['unique']) {
                $table->addUniqueIndex($index['column'], $name, $index['option']);
            }
            else {
                $table->addIndex($index['column'], $name, $index['flag'], $index['option']);
            }
        }

        // add foreign keys
        foreach ($array['foreign'] as $name => $fkey) {
            $table->addForeignKeyConstraint($fkey['table'], array_keys($fkey['column']), array_values($fkey['column']), $fkey['option'], $name);
        }

        return $table;
    }

    private function explodeSql($sqls)
    {
        /// this is used by display only, so very loose.

        $qv = $this->connection->quote('v');
        $quoted_chars = array(
            '"',
            "'",
            $qv[0],
            $this->connection->getDatabasePlatform()->getIdentifierQuoteCharacter(),
        );

        $delimiter = ';';
        $escaped_char = '\\';
        $quoted_list = array_flip($quoted_chars);

        preg_match_all('@.?@us', $sqls, $m);
        $chars = $m[0];

        $last_index = 0;
        $escaping = false;
        $quotings = array_fill_keys($quoted_chars, false);

        $result = array();
        foreach ($chars as $i => $c) {
            if ($c === $escaped_char) {
                $escaping = true;
                continue;
            }
            if (isset($quoted_list[$c])) {
                if (!$escaping) {
                    $quotings[$c] = !$quotings[$c];
                    $escaping = false;
                    continue;
                }
            }

            if (count(array_filter($quotings)) === 0  && $c === $delimiter) {
                $result[] = implode('', array_slice($chars, $last_index, $i - $last_index));
                $last_index = $i + 1;
            }

            $escaping = false;
        }
        $result[] = implode('', array_slice($chars, $last_index));

        return $result;
    }

    private static function array_diff_assoc($array1, $array2)
    {
        foreach ($array1 as $key => $val) {
            if (array_key_exists($key, $array2) && $val === $array2[$key]) {
                unset($array1[$key]);
            }
        }

        return $array1;
    }

    private static function getJsonOption()
    {
        $jop = 0;
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $jop |= JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $jop |= JSON_UNESCAPED_SLASHES;
        }
        if (defined('JSON_PRETTY_PRINT')) {
            $jop |= JSON_PRETTY_PRINT;
        }
        return $jop;
    }

    private static function file_put_contents($filename, $data)
    {
        $dirname = dirname($filename);
        is_dir($dirname) or mkdir($dirname, 0777, true);
        return file_put_contents($filename, $data);
    }

    private static function mb_convert_variables($to_encoding, $from_encoding, &$vars)
    {
        if ($to_encoding === $from_encoding) {
            return $from_encoding;
        }
        return mb_convert_variables($to_encoding, $from_encoding, $vars);
    }
}
