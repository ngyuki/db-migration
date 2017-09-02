<?php
namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\View;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Yaml\Yaml;

class TransporterTest extends AbstractTestCase
{
    /**
     * @var Transporter
     */
    private $transporter;

    /**
     * @var \ReflectionClass
     */
    private $refClass;

    protected function setup()
    {
        parent::setUp();

        $table = new Table('hoge');
        $table->addColumn('id', 'integer');
        $table->addColumn('name', 'string', array('length' => 10));
        $table->addColumn('data', 'float', array('scale' => 5));
        $table->setPrimaryKey(array('id'));
        $table->addIndex(array('name'), 'SECONDARY');
        $table->addUniqueIndex(array('data'));
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('fuga');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(array('id'));
        $table->addForeignKeyConstraint('hoge', array('id'), array('id'));
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('parent');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(array('id'));
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('child');
        $table->addColumn('id', 'integer');
        $table->addColumn('pid', 'integer');
        $table->setPrimaryKey(array('id', 'pid'));
        $table->addForeignKeyConstraint('parent', array('id'), array('id'));
        $indexes = new \ReflectionProperty($table, 'implicitIndexes');
        $indexes->setAccessible(true);
        foreach ($indexes->getValue($table) as $index) {
            /** @var Index $index */
            $table->dropIndex($index->getName());
        }
        $this->oldSchema->dropAndCreateTable($table);

        $view = new View('vvview', 'select * from hoge');
        $this->oldSchema->dropAndCreateView($view);

        $this->insertMultiple($this->old, 'hoge', array_map(function ($i) {
            return array(
                'id'   => $i,
                'name' => 'name-' . $i,
                'data' => $i + 0.5,
            );
        }, range(1, 10)));

        $this->transporter = new Transporter($this->old);
        $this->refClass = new \ReflectionClass($this->transporter);
    }

    /**
     * @test
     */
    function explodeSql()
    {
        $method = $this->refClass->getMethod('explodeSql');
        $method->setAccessible(true);

        $this->assertEquals(array(''), $method->invoke($this->transporter, ''));
        $this->assertEquals(array('hoge', 'fuga'), $method->invoke($this->transporter, 'hoge;fuga'));
        $this->assertEquals(array('hoge', 'fuga', ''), $method->invoke($this->transporter, 'hoge;fuga;'));
        $this->assertEquals(array('"ho;ge"'), $method->invoke($this->transporter, '"ho;ge"'));
        $this->assertEquals(array('aa"ho;ge"bb'), $method->invoke($this->transporter, 'aa"ho;ge"bb'));
        $this->assertEquals(array('h\"o', 'ge'), $method->invoke($this->transporter, 'h\\"o;ge'));
        $this->assertEquals(array('"ho;\";ge"'), $method->invoke($this->transporter, '"ho;\";ge"'));
        $this->assertEquals(array('"ho\';ge"'), $method->invoke($this->transporter, '"ho\';ge"'));
        $this->assertEquals(array('あ', 'い'), $method->invoke($this->transporter, 'あ;い'));
    }

    /**
     * @test
     */
    function exportDDL()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql');
        $this->assertFileContains('CREATE TABLE hoge', self::$tmpdir . '/table.sql');

        $this->transporter->exportDDL(self::$tmpdir . '/table.php');
        $this->transporter->exportDDL(self::$tmpdir . '/table.json');
        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml');

        $php = include self::$tmpdir . '/table.php';
        $json = json_decode(file_get_contents(self::$tmpdir . '/table.json'), true);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));

        $this->assertEquals($php, $json);
        $this->assertEquals($json, $yaml);
        $this->assertEquals($yaml, $php);

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->exportDDL(self::$tmpdir . '/table.ext');
        });
    }

    /**
     * @test
     */
    function exportDDL_filter()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql', array('.*g.*'), array('fuga'));
        $sql = file_get_contents(self::$tmpdir . '/table.sql');
        $this->assertContains('CREATE TABLE hoge', $sql);
        $this->assertNotContains('CREATE TABLE fuga', $sql);

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', array('.*g.*'), array());
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(array('fuga', 'hoge'), array_keys($yaml['table']));

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', array('hoge', 'fuga'), array());
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(array('fuga', 'hoge'), array_keys($yaml['table']));

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', array('.*g.*'), array('fuga'));
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(array('hoge'), array_keys($yaml['table']));
    }

    /**
     * @test
     */
    function exportDDL_noview()
    {
        $this->transporter->enableView(false);
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql');
        $sql = file_get_contents(self::$tmpdir . '/table.sql');
        $this->assertNotContains('CREATE VIEW vvview', $sql);

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml');
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEmpty($yaml['view']);
    }

    /**
     * @test
     */
    function exportDML()
    {
        $this->transporter->exportDML(self::$tmpdir . '/hoge.sql');
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('1', 'name-1', '1.5')", self::$tmpdir . '/hoge.sql');

        $this->transporter->exportDML(self::$tmpdir . '/hoge.php');
        $this->transporter->exportDML(self::$tmpdir . '/hoge.json');
        $this->transporter->exportDML(self::$tmpdir . '/hoge.yaml');

        $php = include self::$tmpdir . '/hoge.php';
        $json = json_decode(file_get_contents(self::$tmpdir . '/hoge.json'), true);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/hoge.yaml'));

        $this->assertEquals($php, $json);
        $this->assertEquals($json, $yaml);
        $this->assertEquals($yaml, $php);

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->exportDML(self::$tmpdir . '/hoge.ext');
        });
    }

    /**
     * @test
     */
    function exportDML_closure()
    {
        $array = var_export(array(
            array(
                'id'   => '1',
                'name' => 'name1',
                'data' => '1.1',
            ),
            array(
                'id'   => '2',
                'name' => 'name2',
                'data' => '1.2',
            ),
            array(
                'id'   => '3',
                'name' => 'name3',
                'data' => '1.3',
            ),
        ), true);
        $contents = "<?php return function(){return $array;};";
        file_put_contents(self::$tmpdir . "/hoge.php", $contents);
        $result = $this->transporter->exportDML(self::$tmpdir . "/hoge.php");
        $this->assertContains('skipped', $result);
        $this->assertStringEqualsFile(self::$tmpdir . "/hoge.php", $contents);
    }

    /**
     * @test
     */
    function exportDML_where()
    {
        $this->transporter->exportDML(self::$tmpdir . '/hoge.sql', array('id=2'), array());
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('2', 'name-2', '2.5')", self::$tmpdir . '/hoge.sql');
        $this->assertFileNotContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('3', 'name-3', '3.5')", self::$tmpdir . '/hoge.sql');
    }

    /**
     * @test
     */
    function exportDML_ignore()
    {
        $this->transporter->exportDML(self::$tmpdir . '/hoge.sql', array(), array('name'));
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `data`, `name`) VALUES ('1', '1.5', '')", self::$tmpdir . '/hoge.sql');
    }

    /**
     * @test
     */
    function importDDL()
    {
        $supported = array('sql', 'php', 'json', 'yaml');
        foreach ($supported as $ext) {
            $this->transporter->exportDDL(self::$tmpdir . "/table.$ext");
        }
        foreach ($supported as $ext) {
            foreach ($this->oldSchema->listTableNames() as $tname) {
                $this->oldSchema->dropTable($tname);
            }
            foreach ($this->oldSchema->listViews() as $vname => $view) {
                $this->oldSchema->dropView($vname);
            }
            $this->transporter->importDDL(self::$tmpdir . "/table.$ext");
            $this->assertTrue($this->oldSchema->tablesExist('hoge'));
            $this->assertTrue($this->oldSchema->tablesExist('fuga'));
            $this->assertEquals(array('vvview'), array_keys($this->oldSchema->listViews()));
        }

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->importDDL(self::$tmpdir . '/table.ext');
        });

        $this->assertException(new \RuntimeException("platform is different"), function () {
            $this->transporter->exportDDL(self::$tmpdir . "/table.json");
            $schema = json_decode(file_get_contents(self::$tmpdir . "/table.json"), true);
            $schema['platform'] = 'unknown';
            file_put_contents(self::$tmpdir . "/table.json", json_encode($schema));
            $this->transporter->importDDL(self::$tmpdir . '/table.json');
        });
    }

    /**
     * @test
     */
    function importDDL_noview()
    {
        $this->transporter->enableView(true);
        $this->transporter->exportDDL(self::$tmpdir . "/table.yaml");
        foreach ($this->oldSchema->listTableNames() as $tname) {
            $this->oldSchema->dropTable($tname);
        }
        foreach ($this->oldSchema->listViews() as $vname => $view) {
            $this->oldSchema->dropView($vname);
        }

        $this->transporter->enableView(false);
        $this->transporter->importDDL(self::$tmpdir . "/table.yaml");
        $this->assertEmpty($this->oldSchema->listViews());
    }

    /**
     * @test
     */
    function importDML()
    {
        $this->old->delete('fuga', array(0));
        $this->insertMultiple($this->old, 'fuga', array_map(function ($i) {
            return array(
                'id' => $i,
            );
        }, range(1, 10)));

        $supported = array('sql', 'php', 'json', 'yaml', 'csv');
        foreach ($supported as $ext) {
            $this->transporter->exportDML(self::$tmpdir . "/fuga.$ext");
        }
        foreach ($supported as $ext) {
            $this->old->delete('fuga', array(0));
            $this->transporter->importDML(self::$tmpdir . "/fuga.$ext");
            $this->assertEquals(10, $this->old->fetchColumn('SELECT COUNT(*) FROM fuga'));
        }

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->importDML(self::$tmpdir . '/fuga.ext');
        });
    }

    /**
     * @test
     */
    function importDML_closure()
    {
        $array = var_export(array(
            array(
                'id'   => '1',
                'name' => 'name1',
                'data' => '1.1',
            ),
            array(
                'id'   => '2',
                'name' => 'name2',
                'data' => '1.2',
            ),
            array(
                'id'   => '3',
                'name' => 'name3',
                'data' => '1.3',
            ),
        ), true);
        file_put_contents(self::$tmpdir . "/hoge.php", "<?php return function(){return $array;};");
        $this->old->delete('hoge', array(0));
        $this->transporter->importDML(self::$tmpdir . "/hoge.php");
        $this->assertEquals(3, $this->old->fetchColumn('SELECT COUNT(*) FROM hoge'));
    }

    /**
     * @test
     */
    function implicit()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.yml');
        $this->assertFileNotContains('IDX_', self::$tmpdir . '/table.yml');

        foreach ($this->oldSchema->listTableNames() as $tname) {
            $this->oldSchema->dropTable($tname);
        }
        foreach ($this->oldSchema->listViews() as $vname => $view) {
            $this->oldSchema->dropView($vname);
        }
        $this->transporter->importDDL(self::$tmpdir . '/table.yml');
        $this->assertCount(1, $this->oldSchema->listTableIndexes('child'));
    }

    /**
     * @test
     */
    function ordered()
    {
        $method = $this->refClass->getMethod('tableToArray');
        $method->setAccessible(true);

        $table = new Table('ordered');
        $table->addColumn('id1', 'integer');
        $table->addColumn('id2', 'integer');
        $table->addColumn('id3', 'integer');
        $table->addIndex(array('id1'), 'idx_zzz');
        $table->addIndex(array('id2'), 'idx_yyy');
        $table->addIndex(array('id3'), 'idx_xxx');
        $table->setPrimaryKey(array('id1', 'id2', 'id3'));
        $table->addForeignKeyConstraint('parent', array('id1'), array('id'), array(), 'fk_zzz');
        $table->addForeignKeyConstraint('parent', array('id2'), array('id'), array(), 'fk_yyy');
        $table->addForeignKeyConstraint('parent', array('id3'), array('id'), array(), 'fk_xxx');

        $tablearray = $method->invoke($this->transporter, $table);
        $this->assertEquals(array('primary', 'idx_xxx', 'idx_yyy', 'idx_zzz'), array_keys($tablearray['index']));
        $this->assertEquals(array('fk_xxx', 'fk_yyy', 'fk_zzz'), array_keys($tablearray['foreign']));
    }

    /**
     * @test
     */
    function bulkmode()
    {
        $insert = $this->refClass->getMethod('insert');
        $insert->setAccessible(true);

        $affected = $insert->invoke($this->transporter, 'hoge', array());
        $this->assertEquals(0, $affected);

        $this->old->delete('hoge', array(0));
        $this->transporter->setBulkMode(false);
        $affected = $insert->invoke($this->transporter, 'hoge', array(
            array(
                'id'   => 1,
                'name' => 'r1',
                'data' => 1,
            ),
            array(
                'id'   => 2,
                'name' => 'r2',
                'data' => 2,
            ),
        ));
        $this->assertEquals(2, $affected);
        $this->assertEquals(2, $this->old->fetchColumn('SELECT COUNT(*) FROM hoge'));

        $this->old->delete('hoge', array(0));
        $this->transporter->setBulkMode(true);
        $affected = $insert->invoke($this->transporter, 'hoge', array(
            array(
                'id'   => 1,
                'name' => 'r1',
                'data' => 1,
            ),
            array(
                'data' => 2,
                'id'   => 2,
                'name' => 'r2',
            ),
        ));
        $this->assertEquals(2, $affected);
        $this->assertEquals(2, $this->old->fetchColumn('SELECT COUNT(*) FROM hoge'));
    }

    /**
     * @test
     */
    function encoding()
    {
        $this->transporter->setEncoding('sql', 'SJIS-win');
        $this->transporter->setEncoding('php', 'SJIS-win');
        $this->transporter->setEncoding('json', 'SJIS-win');
        $this->transporter->setEncoding('yaml', 'SJIS-win');
        $this->transporter->setEncoding('csv', 'SJIS-win');

        $supported = array(
            'sql'  => "INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('1', 'あいうえお', '3.14');
",
            'php'  => "<?php return array(
array (
  'id' => '1',
  'name' => 'あいうえお',
  'data' => '3.14',
)
);
",
            'json' => '[
{
    "id": "1",
    "name": "あいうえお",
    "data": "3.14"
}
]
',
            'yaml' => "-
    id: '1'
    name: あいうえお
    data: '3.14'
",
            'csv'  => "id,name,data
1,あいうえお,3.14
",
        );
        mb_convert_variables('SJIS-win', mb_internal_encoding(), $supported);

        $this->old->delete('hoge', array(0));
        $this->old->insert('hoge', array(
            'id'   => 1,
            'name' => 'あいうえお',
            'data' => 3.14,
        ));

        foreach ($supported as $ext => $expected) {
            $this->transporter->exportDML(self::$tmpdir . "/hoge.$ext");
            $this->assertStringEqualsFile(self::$tmpdir . "/hoge.$ext", $expected);
        }
        foreach ($supported as $ext => $expected) {
            $this->old->delete('hoge', array(0));
            $this->transporter->importDML(self::$tmpdir . "/hoge.$ext");
            $this->assertEquals(array(
                'id'   => 1,
                'name' => 'あいうえお',
                'data' => 3.14,
            ), $this->old->fetchAssoc('SELECT * FROM hoge'));
        }
    }

    /**
     * @test
     */
    function mb_convert_variables()
    {
        $mb_convert_variables = $this->refClass->getMethod('mb_convert_variables');
        $mb_convert_variables->setAccessible(true);

        $string = 'あ';

        $actual = $mb_convert_variables->invokeArgs($this->transporter, array(
            'UTF-8',
            'UTF-8',
            &$string
        ));
        $this->assertEquals($actual, 'UTF-8');
        $this->assertEquals('あ', $string);

        $actual = $mb_convert_variables->invokeArgs($this->transporter, array(
            'SJIS',
            'UTF-8',
            &$string
        ));
        $this->assertEquals($actual, 'UTF-8');
        $this->assertEquals('あ', mb_convert_encoding($string, 'UTF-8', 'SJIS'));
    }
}
