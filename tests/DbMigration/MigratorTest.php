<?php
namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\SchemaException;
use ryunosuke\DbMigration\Migrator;
use ryunosuke\DbMigration\MigrationException;

class MigratorTest extends AbstractTestCase
{
    /**
     * @var Connection
     */
    protected $old, $new;

    /**
     * @closurable
     *
     * @param Connection $old
     * @param Connection $new
     * @param string $table
     * @param array $wheres
     * @param array $ignroes
     * @return array
     */
    private function getDML($old, $new, $table, $wheres = array(), $ignroes = array())
    {
        return Migrator::getDML($old, $new, $table, (array) $wheres, (array) $ignroes);
    }

    protected function setup()
    {
        parent::setUp();

        // create migration table different name
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('hoge', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('fuga', 'integer', 'id'));

        // create migration table no pkey
        $table = $this->createSimpleTable('nopkey', 'integer', 'id');
        $table->dropPrimaryKey();
        $this->oldSchema->dropAndCreateTable($table);
        $this->newSchema->dropAndCreateTable($table);

        // create migration table different pkey
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('diffpkey', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('diffpkey', 'integer', 'seq'));

        // create migration table different column
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('diffcolumn', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('diffcolumn', 'integer', 'id', 'seq'));

        // create migration table different type
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('difftype', 'string', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('difftype', 'integer', 'id'));

        // create migration table different record
        $table = $this->createSimpleTable('foo', 'integer', 'id');
        $table->addColumn('c_int', 'integer');
        $table->addColumn('c_float', 'float');
        $table->addColumn('c_varchar', 'string');
        $table->addColumn('c_text', 'text');
        $table->addColumn('c_datetime', 'datetime');

        $this->oldSchema->dropAndCreateTable($table);
        $this->newSchema->dropAndCreateTable($table);

        $this->insertMultiple($this->old, 'foo', array(
            '{"id":0,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":1,"c_int":2,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":2,"c_int":1,"c_float":2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":3,"c_int":1,"c_float":1,"c_varchar":"charX","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":4,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"textX","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":5,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"1999-01-01 00:00:00"}',
            '{"id":6,"c_int":2,"c_float":2,"c_varchar":"charX","c_text":"textX","c_datetime":"1999-01-01 00:00:00"}',
            '{"id":8,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":9,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":99,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
        ));
        $this->insertMultiple($this->new, 'foo', array(
            '{"id":-2,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":-1,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":0,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":1,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":2,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":3,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":4,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":5,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":6,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":99,"c_int":2,"c_float":1.4,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
        ));
    }

    /**
     * @test
     */
    function migrate_ddl()
    {
        $ddls = Migrator::getDDL($this->old, $this->new);

        $this->assertContainsString('CREATE TABLE fuga', $ddls);
        $this->assertContainsString('DROP TABLE hoge', $ddls);
    }

    /**
     * @test
     */
    function migrate_dml()
    {
        $dmls = $this->getDML($this->old, $this->new, 'foo', '1');
        $this->assertCount(11, $dmls);

        foreach ($dmls as $sql) {
            $this->old->exec($sql);
        }

        $dmls = $this->getDML($this->old, $this->new, 'foo', '1');
        $this->assertCount(0, $dmls);
    }

    /**
     * @test
     */
    function migrate_dml_where()
    {
        $dmls = $this->getDML($this->old, $this->new, 'foo', 'id = -1');
        $this->assertCount(1, $dmls);
    }

    /**
     * @test
     */
    function migrate_dml_ignore()
    {
        // c_int,c_float しか違いがないので無視すれば差分なしのはず
        $dmls = $this->getDML($this->old, $this->new, 'foo', 'id = 99', array('c_int', 'c_float'));
        $this->assertCount(0, $dmls);

        // 修飾してもテーブルが一致すれば同様のはず
        $dmls = $this->getDML($this->old, $this->new, 'foo', 'id = 99', array('foo.c_int', 'foo.c_float'));
        $this->assertCount(0, $dmls);

        // クォートできるはず
        $dmls = $this->getDML($this->old, $this->new, 'foo', 'id = 99', array('`foo`.`c_int`', '`c_float`'));
        $this->assertCount(0, $dmls);

        // テーブルが不一致なら普通に差分ありのはず
        $dmls = $this->getDML($this->old, $this->new, 'foo', 'id = 99', array('bar.c_int', 'bar.c_float'));
        $this->assertCount(1, $dmls);

        // INSERT には影響しないはず
        $dmls1 = $this->getDML($this->old, $this->new, 'foo', 'id = -1');
        $dmls2 = $this->getDML($this->old, $this->new, 'foo', 'id = -1', array('c_int', 'c_float', 'c_varchar'));
        $this->assertEquals($dmls1, $dmls2);
    }

    /**
     * @test
     */
    function migrate_dml_name()
    {
        $e = new SchemaException("There is no table with name", SchemaException::TABLE_DOESNT_EXIST);

        $this->assertException($e, $this->getDML, $this->old, $this->new, 'notable', '1');
    }

    /**
     * @test
     */
    function migrate_dml_nopkey()
    {
        $e = new MigrationException("has no primary key");

        $this->assertException($e, $this->getDML, $this->old, $this->new, 'nopkey', '1');
    }

    /**
     * @test
     */
    function migrate_dml_equals()
    {
        $e = new MigrationException("has different definition");

        $this->assertException($e, $this->getDML, $this->old, $this->new, 'diffpkey', '1');
        $this->assertException($e, $this->getDML, $this->old, $this->new, 'diffcolumn', '1');
        $this->assertException($e, $this->getDML, $this->old, $this->new, 'difftype', '1');
    }
}
