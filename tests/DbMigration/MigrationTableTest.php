<?php
namespace ryunosuke\Test\DbMigration;

use ryunosuke\DbMigration\MigrationTable;

class MigrationTableTest extends AbstractTestCase
{
    public function test_create_drop()
    {
        $migrationTable = new MigrationTable($this->old, 'migtable');

        $this->assertTrue($migrationTable->create());
        $this->assertFalse($migrationTable->create());
        $this->assertTrue($migrationTable->drop());
        $this->assertFalse($migrationTable->drop());
    }

    public function test_glob()
    {
        $migrationTable = new MigrationTable($this->old, 'migtable');
        $versions = $migrationTable->glob(__DIR__ . '/_files/migs');
        $this->assertEquals(array (
            'aaa.sql' => 'insert into hoge values ()',
        ), $versions);
    }

    public function test_attach_detach()
    {
        $migrationTable = new MigrationTable($this->old, 'migtable');
        $migrationTable->create();

        $this->assertEquals(3, $migrationTable->attach(array('aaa', 'bbb', 'ccc')));
        $this->assertEquals(array('aaa', 'bbb', 'ccc'), array_keys($migrationTable->fetch()));
        $this->assertEquals(2, $migrationTable->detach(array('aaa', 'ccc')));
        $this->assertEquals(array('bbb'), array_keys($migrationTable->fetch()));
    }
}
