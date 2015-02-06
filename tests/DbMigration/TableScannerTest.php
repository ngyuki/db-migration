<?php
namespace ryunosuke\Test\DbMigration;

use ryunosuke\Test\DbMigration\AbstractTestCase;
use ryunosuke\DbMigration\TableScanner;

class TableScannerTest extends AbstractTestCase
{

    private $scanner;

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
        $this->assertEquals('TRUE', $condition);
        
        $condition = $this->invoke('parseCondition', 'hoge.fuga = 1', '`');
        $this->assertEquals('TRUE', $condition);
        
        $condition = $this->invoke('parseCondition', 'fuga.id = 1', '`');
        $this->assertEquals('TRUE', $condition);
        
        $condition = $this->invoke('parseCondition', '`fuga`.`id` = 1', '`');
        $this->assertEquals('TRUE', $condition);
        
        $condition = $this->invoke('parseCondition', 'hoge.id = 1', '`');
        $this->assertEquals('hoge.id = 1', $condition);
        
        $condition = $this->invoke('parseCondition', 'id = 1', '`');
        $this->assertEquals('id = 1', $condition);
        
        $condition = $this->invoke('parseCondition', '`hoge`.`id` = 1', '`');
        $this->assertEquals('`hoge`.`id` = 1', $condition);
        
        $condition = $this->invoke('parseCondition', '`id` = 1', '`');
        $this->assertEquals('`id` = 1', $condition);
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
        $rows = $this->invoke('getRecordFromPrimaryKeys', array());
        
        $this->assertCount(0, $rows->fetchAll());
    }

    /**
     * @test
     */
    function getRecordFromPrimaryKeys_page()
    {
        $this->insertMultiple($this->old, 'hoge', array_map(function ($i)
        {
            return array(
                'id' => $i
            );
        }, range(1, 10)));
        
        TableScanner::$pageCount = 4;
        
        $method = 'getRecordFromPrimaryKeys';
        $tuples = $this->scanner->getPrimaryRows();
        
        $this->assertCount(4, $this->invoke($method, $tuples, 0)->fetchAll());
        $this->assertCount(4, $this->invoke($method, $tuples, 1)->fetchAll());
        $this->assertCount(2, $this->invoke($method, $tuples, 2)->fetchAll());
        $this->assertCount(0, $this->invoke($method, $tuples, 3)->fetchAll());
    }
}
