<?php
namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Schema\Table;
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
        $table->addIndex(array('name'));
        $table->addUniqueIndex(array('data'));
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('fuga');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(array('id'));
        $table->addForeignKeyConstraint('hoge', array('id'), array('id'));
        $this->oldSchema->dropAndCreateTable($table);

        $this->insertMultiple($this->old, 'hoge', array_map(function ($i) {
            return array(
                'id'   => $i,
                'name' => 'name-' . $i,
                'data' => $i + 0.5,
            );
        }, range(1, 10)));

        $this->transporter = new Transporter($this->old, $table, 'TRUE');
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
    function exportDML()
    {
        $this->transporter->exportDML(self::$tmpdir . '/hoge.sql', array(), array());
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('1', 'name-1', '1.5')", self::$tmpdir . '/hoge.sql');

        $this->transporter->exportDML(self::$tmpdir . '/hoge.php', array(), array());
        $this->transporter->exportDML(self::$tmpdir . '/hoge.json', array(), array());
        $this->transporter->exportDML(self::$tmpdir . '/hoge.yaml', array(), array());

        $php = include self::$tmpdir . '/hoge.php';
        $json = json_decode(file_get_contents(self::$tmpdir . '/hoge.json'), true);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/hoge.yaml'));

        $this->assertEquals($php, $json);
        $this->assertEquals($json, $yaml);
        $this->assertEquals($yaml, $php);

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->exportDML(self::$tmpdir . '/hoge.ext', array(), array());
        });
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
            $this->oldSchema->dropTable('fuga');
            $this->oldSchema->dropTable('hoge');
            $this->transporter->importDDL(self::$tmpdir . "/table.$ext");
            $this->assertTrue($this->oldSchema->tablesExist('hoge'));
            $this->assertTrue($this->oldSchema->tablesExist('fuga'));
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
    function importDML()
    {
        $this->old->delete('fuga', array(0));
        $this->insertMultiple($this->old, 'fuga', array_map(function ($i) {
            return array(
                'id' => $i,
            );
        }, range(1, 10)));

        $supported = array('sql', 'php', 'json', 'yaml');
        foreach ($supported as $ext) {
            $this->transporter->exportDML(self::$tmpdir . "/fuga.$ext", array(), array());
        }
        foreach ($supported as $ext) {
            $this->old->delete('fuga', array(0));
            $this->transporter->importDML(self::$tmpdir . "/fuga.$ext");
            $this->assertEquals(10, $this->old->fetchColumn('SELECT COUNT(*) FROM hoge'));
        }

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->importDML(self::$tmpdir . '/fuga.ext');
        });
    }
}
