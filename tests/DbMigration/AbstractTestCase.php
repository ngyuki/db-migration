<?php
namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;

abstract class AbstractTestCase extends \PHPUnit_Framework_TestCase
{
    protected static $tmpdir;

    /**
     * @var Connection
     */
    protected $connection, $old, $new;

    /**
     * @var AbstractSchemaManager
     */
    protected $oldSchema, $newSchema;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        self::$tmpdir = sys_get_temp_dir() . '/ryumig';
        is_dir(self::$tmpdir) or mkdir(self::$tmpdir, 0777, true);
    }

    /**
     * for get closure of method
     *
     * @param string $name
     * @return \Closure
     */
    public function __get($name)
    {
        // compatible PHPUnit_Framework_TestCase::__get
        if (is_callable('parent::__get')) {
            /** @noinspection PhpUndefinedMethodInspection */
            return parent::__get($name);
        }

        // if exsists method and @closurable, return that closure
        if (method_exists($this, $name)) {
            $refclass = new \ReflectionClass($this);
            $method = $refclass->getMethod($name);
            if (strpos($method->getDocComment(), '@closurable') !== false) {
                $method->setAccessible(true);
                return $method->getClosure($this);
            }
        }
    }

    protected function setUp()
    {
        parent::setUp();

        array_map('unlink', glob(self::$tmpdir . '/*'));

        $ref = new \ReflectionProperty('ryunosuke\\DbMigration\\Migrator', 'schemas');
        $ref->setAccessible(true);
        $ref->setValue(array());

        // drop schema
        $this->connection = $this->getConnection('old', '');
        $schema = $this->connection->getSchemaManager();
        $schema->dropAndCreateDatabase($GLOBALS['old_db_name']);
        $schema->dropAndCreateDatabase($GLOBALS['new_db_name']);

        // get connection
        $this->old = $this->getConnection('old');
        $this->new = $this->getConnection('new');

        // get schema
        $this->oldSchema = $this->old->getSchemaManager();
        $this->newSchema = $this->new->getSchemaManager();
    }

    public function tearDown()
    {
        parent::tearDown();
        $this->connection->close();
        $this->old->close();
        $this->new->close();
    }

    public function getConnection($prefix, $dbname = null)
    {
        $g_keys = array(
            'type'     => "{$prefix}_db_type",
            'host'     => "{$prefix}_db_host",
            'port'     => "{$prefix}_db_port",
            'name'     => "{$prefix}_db_name",
            'username' => "{$prefix}_db_username",
            'password' => "{$prefix}_db_password"
        );

        $params = array(
            'driver'   => $GLOBALS[$g_keys['type']],
            'host'     => $GLOBALS[$g_keys['host']],
            'port'     => $GLOBALS[$g_keys['port']],
            'dbname'   => $GLOBALS[$g_keys['name']],
            'user'     => $GLOBALS[$g_keys['username']],
            'password' => $GLOBALS[$g_keys['password']]
        );

        if ($dbname !== null) {
            $params['dbname'] = $dbname;
        }

        return DriverManager::getConnection($params);
    }

    public function createSimpleTable($name, $type)
    {
        $table = new Table($name);
        $columns = array_slice(func_get_args(), 2);
        foreach ($columns as $column) {
            $table->addColumn($column, $type);
        }
        $table->setPrimaryKey(array(
            reset($columns)
        ));
        return $table;
    }

    public function insertMultiple(Connection $conn, $table, $records)
    {
        $conn->beginTransaction();

        foreach ($records as $record) {
            if (is_string($record)) {
                $record = json_decode($record, true);
            }
            $conn->insert($table, $record);
        }

        $conn->commit();
    }

    public static function assertException(\Exception $e, callable $callback)
    {
        try {
            call_user_func_array($callback, array_slice(func_get_args(), 2));
        }
        catch (\PHPUnit_Framework_Exception $ex) {
            throw $ex;
        }
        catch (\Exception $ex) {
            self::assertInstanceOf(get_class($e), $ex);
            self::assertEquals($e->getCode(), $ex->getCode());
            if (strlen($e->getMessage()) > 0) {
                self::assertContains($e->getMessage(), $ex->getMessage());
            }
            return;
        }
        self::fail(get_class($e) . ' is not thrown');
    }

    public static function assertExceptionMessage($message, callable $callback)
    {
        $args = func_get_args();
        $args[0] = new \Exception($message);
        call_user_func_array('self::assertException', $args);
    }

    public static function assertContainsString($needle, $haystack, $message = '')
    {
        if (is_array($haystack) || is_object($haystack) && $haystack instanceof \Traversable) {
            foreach ($haystack as $val) {
                if (strpos($val, $needle) !== false) {
                    //for assertion count
                    self::assertTrue(true);
                    return;
                }
            }
            self::assertContains($needle, array(), $message);
        }
        elseif (is_string($haystack)) {
            self::assertContains($needle, $haystack, $message);
        }
    }

    public static function assertFileContains($needle, $haystack, $message = '')
    {
        self::assertContains($needle, file_get_contents($haystack), $message);
    }

    public static function assertFileNotContains($needle, $haystack, $message = '')
    {
        self::assertNotContains($needle, file_get_contents($haystack), $message);
    }
}
