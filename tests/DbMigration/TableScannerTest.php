<?php
namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use ryunosuke\DbMigration\TableScanner;

class TableScannerTest extends AbstractTestCase
{
    /**
     * @var TableScanner
     */
    private $scanner;

    /**
     * @var \ReflectionClass
     */
    private $refClass;

    protected function setup()
    {
        parent::setUp();
        
        $table = $this->createSimpleTable('hoge', 'integer', 'id');
        $this->oldSchema->dropAndCreateTable($table);
        
        $this->scanner = new TableScanner($this->old, $table, 'TRUE');
        $this->refClass = new \ReflectionClass($this->scanner);
    }

    private function invoke($methodName, $args)
    {
        $method = $this->refClass->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->scanner, array_slice(func_get_args(), 1));
    }

    /**
     * @test
     */
    function parseCondition()
    {
        $condition = $this->invoke('parseCondition', '', '`');
        $this->assertEquals('1', $condition);
        
        $condition = $this->invoke('parseCondition', 'hoge.fuga = 1', '`');
        $this->assertEquals('1', $condition);
        
        $condition = $this->invoke('parseCondition', 'fuga.id = 1', '`');
        $this->assertEquals('1', $condition);
        
        $condition = $this->invoke('parseCondition', '`fuga`.`id` = 1', '`');
        $this->assertEquals('1', $condition);
        
        $condition = $this->invoke('parseCondition', 'hoge.id = 1', '`');
        $this->assertEquals('hoge.id = 1', $condition);
        
        $condition = $this->invoke('parseCondition', 'id = 1', '`');
        $this->assertEquals('id = 1', $condition);
        
        $condition = $this->invoke('parseCondition', '`hoge`.`id` = 1', '`');
        $this->assertEquals('`hoge`.`id` = 1', $condition);
        
        $condition = $this->invoke('parseCondition', '`id` = 1', '`');
        $this->assertEquals('`id` = 1', $condition);

        $condition = $this->invoke('parseCondition', array('`id` > 1', '`id` < 10'), '`');
        $this->assertEquals('`id` > 1 AND `id` < 10', $condition);
    }

    /**
     * @test
     */
    function commentize()
    {
        $comment = $this->invoke('commentize', array(
            'col1' => str_repeat('X', 100),
            'col2' => str_repeat('あ', 100),
            'col3' => null
        ), 10);
        
        $this->assertContains('XXXXXXX...', $comment);
        $this->assertContains('あああ...', $comment);
        $this->assertContains('NULL', $comment);
    }

    /**
     * @test
     */
    function getRecordFromPrimaryKeys_empty()
    {
        $rows = $this->invoke('getRecordFromPrimaryKeys', array(), true);
        
        $this->assertCount(0, $rows->fetchAll());
    }

    /**
     * @test
     */
    function getRecordFromPrimaryKeys_page()
    {
        $this->insertMultiple($this->old, 'hoge', array_map(function ($i) {
            return array(
                'id' => $i
            );
        }, range(1, 10)));
        
        TableScanner::$pageCount = 4;
        
        $method = 'getRecordFromPrimaryKeys';
        $tuples = $this->scanner->getPrimaryRows();
        
        $this->assertCount(4, $this->invoke($method, $tuples, true, 0)->fetchAll());
        $this->assertCount(4, $this->invoke($method, $tuples, true, 1)->fetchAll());
        $this->assertCount(2, $this->invoke($method, $tuples, true, 2)->fetchAll());
        $this->assertCount(0, $this->invoke($method, $tuples, true, 3)->fetchAll());
    }

    /**
     * @test
     */
    function fillDefaultValue()
    {
        $con = DriverManager::getConnection(array('pdo' => new \PDO('sqlite::memory:')));

        $table = new Table('deftable',
            array(
                new Column('id', Type::getType('integer')),
                new Column('havedef', Type::getType('integer'), array('default' => 9)),
                new Column('nullable', Type::getType('integer'), array('notnull' => false)),
            ),
            array(new Index('PRIMARY', array('id'), true, true))
        );

        $con->getSchemaManager()->dropAndCreateTable($table);

        $scanner = new TableScanner($con, $table, '1');

        $this->assertEquals(array(
            'id'       => 0,
            'havedef'  => 9,
            'nullable' => null,
        ), $scanner->fillDefaultValue(array()));
    }

    /**
     * @test
     */
    function getInsertSql_no_mysql()
    {
        $old = DriverManager::getConnection(array('pdo' => new \PDO('sqlite::memory:')));
        $new = DriverManager::getConnection(array('pdo' => new \PDO('sqlite::memory:')));

        $table = new Table('hogetable',
            array(new Column('id', Type::getType('integer'))),
            array(new Index('PRIMARY', array('id'), true, true))
        );

        $old->getSchemaManager()->dropAndCreateTable($table);
        $new->getSchemaManager()->dropAndCreateTable($table);

        $this->insertMultiple($new, 'hogetable', array(array('id' => 1)));

        $scanner = new TableScanner($old, $table, '1');
        $inserts = $scanner->getInsertSql(array(array('id' => 1)), new TableScanner($new, $table, '1'));

        // sqlite no support INSERT SET syntax. Therefore VALUES (value)
        $this->assertContains('INSERT INTO "hogetable" ("id") VALUES', $inserts[0]);
    }
}
