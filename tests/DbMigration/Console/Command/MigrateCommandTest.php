<?php
namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use ryunosuke\DbMigration\Console\Command\MigrateCommand;
use ryunosuke\Test\DbMigration\AbstractTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrateCommandTest extends AbstractTestCase
{
    /**
     * @var Application
     */
    private $app;

    protected function setup()
    {
        parent::setUp();
        
        $command = new MigrateCommand();
        $command->setPreMigration('get_class');
        $command->setPostMigration('get_class');
        
        $migtable = $this->createSimpleTable('migtable', 'integer', 'id', 'code');
        $migtable->addUniqueIndex(array(
            'code'
        ), 'unq_index');
        
        $longtable = $this->createSimpleTable('longtable', 'integer', 'id');
        $longtable->addColumn('text_data', 'text');
        $longtable->addColumn('blob_data', 'blob');
        $nopkeytable = $this->createSimpleTable('nopkeytable', 'integer', 'id');
        $nopkeytable->dropPrimaryKey();
        $this->oldSchema->dropAndCreateTable($migtable);
        $this->oldSchema->dropAndCreateTable($longtable);
        $this->oldSchema->dropAndCreateTable($nopkeytable);
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('difftable', 'integer', 'code'));
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('igntable', 'integer', 'id', 'code'));
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('unqtable', 'integer', 'id', 'code'));
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('sametable', 'integer', 'id'));
        
        $this->old->insert('migtable', array(
            'id'   => 5,
            'code' => 2
        ));
        $this->old->insert('migtable', array(
            'id'   => 9,
            'code' => 999
        ));
        $this->old->insert('sametable', array(
            'id' => 9
        ));
        
        $helperSet = new HelperSet(array(
            'db' => new ConnectionHelper($this->old)
        ));
        
        $this->app = new Application('Test');
        $this->app->setCatchExceptions(false);
        $this->app->setAutoExit(false);
        $this->app->setHelperSet($helperSet);
        $this->app->add($command);
    }

    private function getFile($filename)
    {
        if ($filename !== null) {
            $filename = "\\_files\\$filename";
        }
        return str_replace('\\', '/', __DIR__ . $filename);
    }

    /**
     * @closurable
     * @param array $inputArray
     * @return string
     */
    private function runApp($inputArray)
    {
        $inputArray = array(
                'command' => 'dbal:migrate'
            ) + $inputArray + array(
                '-n' => true
            );
        
        $input = new ArrayInput($inputArray);
        $output = new BufferedOutput();
        
        $this->app->run($input, $output);

        return $output->fetch();
    }

    /**
     * @test
     */
    function run_file()
    {
        $result = $this->runApp(array(
            '-vvv'  => true,
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertContains('ALTER TABLE igntable', $result);
        $this->assertContains('DELETE FROM `migtable`', $result);
        $this->assertContains('INSERT INTO `migtable`', $result);
        $this->assertContains('UPDATE `migtable` SET', $result);
        $this->assertNotContains('`sametable`', $result);
    }

    /**
     * @test
     */
    function run_target()
    {
        $this->oldSchema->dropAndCreateDatabase('migration_tests_target');
        $result = $this->runApp(array(
            '-vvv'     => true,
            '--target' => $this->old->getHost() . '/migration_tests_target',
            'files'    => array(
                $this->getFile('table.sql'),
            )
        ));

        $this->assertContains('CREATE TABLE difftable', $result);
        $this->assertContains('CREATE TABLE igntable', $result);
        $this->assertContains('CREATE TABLE longtable', $result);
        $this->assertContains('CREATE TABLE migtable', $result);
        $this->assertContains('CREATE TABLE nopkeytable', $result);
        $this->assertContains('CREATE TABLE sametable', $result);
        $this->assertContains('CREATE TABLE unqtable', $result);
    }

    /**
     * @test
     */
    function run_target_config()
    {
        $this->oldSchema->dropAndCreateDatabase('migration_tests_target');
        $result = $this->runApp(array(
            '-vvv'     => true,
            '--target' => $this->old->getHost() . '/migration_tests_target',
            'files'    => array(
                $this->getFile('table.sql'),
            )
        ));

        $this->assertContains('CREATE TABLE difftable', $result);
        $this->assertContains('CREATE TABLE igntable', $result);
        $this->assertContains('CREATE TABLE longtable', $result);
        $this->assertContains('CREATE TABLE migtable', $result);
        $this->assertContains('CREATE TABLE nopkeytable', $result);
        $this->assertContains('CREATE TABLE sametable', $result);
        $this->assertContains('CREATE TABLE unqtable', $result);
    }

    /**
     * @test
     */
    function run_dsn()
    {
        $result = $this->runApp(array(
            '-vvv'  => true,
            '--dsn' => $this->new->getHost(),
            'files' => array()
        ));
        
        $this->assertContains($this->old->getDatabase(), $result);
        
        $result = $this->runApp(array(
            '-vvv'  => true,
            '--dsn' => $this->new->getHost() . '/' . $this->new->getDatabase(),
            'files' => array()
        ));
        
        $this->assertContains($this->new->getDatabase(), $result);
    }

    /**
     * @test
     */
    function run_schema()
    {
        $this->newSchema->dropAndCreateDatabase($this->new->getDatabase());

        $result = $this->runApp(array(
            '-vvv'      => true,
            '--schema'  => $this->new->getDatabase(),
            '--rebuild' => '1',
            'files'     => array(
                $this->getFile('table.sql'),
            )
        ));

        $this->assertContains($this->new->getDatabase() . ' is created.', $result);
        $this->assertContains($this->new->getDatabase() . ' is dropped.', $result);
    }

    /**
     * @test
     */
    function run_type_ddl()
    {
        $result = $this->runApp(array(
            '--type' => 'ddl',
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertContains('ALTER TABLE igntable', $result);
        $this->assertNotContains('DELETE FROM `migtable`', $result);
        $this->assertNotContains('INSERT INTO `migtable`', $result);
        $this->assertNotContains('UPDATE `migtable` SET', $result);
    }

    /**
     * @test
     */
    function run_type_dml()
    {
        $result = $this->runApp(array(
            '--type' => 'dml',
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertNotContains('ALTER TABLE igntable', $result);
        $this->assertContains('DELETE FROM `migtable`', $result);
        $this->assertContains('INSERT INTO `migtable`', $result);
        $this->assertContains('UPDATE `migtable` SET', $result);
    }

    /**
     * @test
     */
    function run_xcludes()
    {
        $result = $this->runApp(array(
            '-v'        => true,
            '--include' => array(
                'migtable'
            ),
            '--exclude' => array(
                'igntable',
                'migtable'
            ),
            'files'     => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertNotContains('DELETE FROM `migtable`', $result);
        $this->assertNotContains('INSERT INTO `migtable`', $result);
        $this->assertNotContains('UPDATE `migtable` SET', $result);
        $this->assertNotContains('`sametable`', $result);
    }

    /**
     * @test
     */
    function run_nofilenodsn()
    {
        $this->assertExceptionMessage("require 'file' argument or 'dsn' option", $this->runApp, array(
            '--dsn' => null,
            'files' => array()
        ));
    }

    /**
     * @test
     */
    function run_invalidfile()
    {
        $this->assertExceptionMessage("'very invalid sql'", $this->runApp, array(
            'files' => array(
                $this->getFile('invalid.sql')
            )
        ));
    }

    /**
     * @test
     */
    function run_notfound()
    {
        $this->assertExceptionMessage('does not exist', $this->runApp, array(
            'files' => array(
                $this->getFile('notfound.sql')
            )
        ));
    }

    /**
     * @test
     */
    function run_notfile()
    {
        $this->assertExceptionMessage('is directory', $this->runApp, array(
            'files' => array(
                $this->getFile(null)
            )
        ));
    }

    /**
     * @test
     */
    function run_throwable_ddl()
    {
        $this->insertMultiple($this->old, 'unqtable', array(
            array(
                'id'   => 2,
                'code' => 7
            ),
            array(
                'id'   => 3,
                'code' => 7
            )
        ));
        
        $result = $this->runApp(array(
            '--force' => true,
            'files'   => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertContains("key 'unq_index'", $result);
        
        $this->assertExceptionMessage("key 'unq_index'", $this->runApp, array(
            '--force' => false,
            'files'   => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
    }

    /**
     * @test
     */
    function run_throwable_dml()
    {
        $this->insertMultiple($this->old, 'migtable', array(
            array(
                'id'   => 19,
                'code' => 20
            ),
            array(
                'id'   => 20,
                'code' => 19
            )
        ));
        
        $result = $this->runApp(array(
            '--force' => true,
            'files'   => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertContains("key 'unq_index'", $result);
        
        $count = $this->old->fetchColumn("select COUNT(*) from migtable");
        
        $this->assertExceptionMessage("key 'unq_index'", $this->runApp, array(
            '--force' => false,
            'files'   => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertEquals($count, $this->old->fetchColumn("select COUNT(*) from migtable"));
    }

    /**
     * @test
     */
    function run_throwable_migration()
    {
        $result = $this->runApp(array(
            '-v'     => true,
            '--type' => 'dml',
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertContains('difftable    is skipped by has different definition between schema', $result);
        $this->assertContains('nopkeytable  is skipped by has no primary key', $result);
    }

    /**
     * @test
     */
    function run_postmigration()
    {
        // replace pre/post callback
        $checker = null;
        /** @var MigrateCommand $command */
        $command = $this->app->get('dbal:migrate');
        $command->setPreMigration(function () use (&$checker) {
            $checker = true;
            throw new \RuntimeException('pre migration');
        });
        $command->setPostMigration(function () use (&$checker) {
            $checker = false;
        });
        
        $this->assertExceptionMessage('pre migration', $this->runApp, array(
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertFalse($checker);
    }

    /**
     * @test
     */
    function run_omission_sql()
    {
        $this->insertMultiple($this->old, 'migtable', array_map(function ($i) {
            return array(
                'id'   => $i + 100,
                'code' => $i * 10
            );
        }, range(1, 1001)));
        
        $result = $this->runApp(array(
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertLessThanOrEqual(1000, substr_count($result, 'DELETE FROM `migtable`'));
    }

    /**
     * @test
     */
    function run_omission_dml()
    {
        $this->old->insert('longtable', array(
            'id'        => 1,
            'text_data' => str_pad('', 2048, 'X'),
            'blob_data' => str_pad('', 2048, 'Y')
        ));
        
        $result = $this->runApp(array(
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertContains('...(omitted)', $result);
    }

    /**
     * @test
     */
    function run_verbosity()
    {
        $result = $this->runApp(array(
            '-c'    => true,
            '-q'    => true,
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertEmpty($result);
        
        $result = $this->runApp(array(
            '-c'    => true,
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertNotContains('is skipped by no diff', $result);
        
        $result = $this->runApp(array(
            '-c'    => true,
            '-v'    => true,
            '-i'    => 'notexist,igntable,nopkeytable,sametable,difftable',
            '-e'    => 'difftable',
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));

        $this->assertContains('is skipped by include option', $result);
        $this->assertContains('is skipped by exclude option', $result);
        $this->assertContains('is skipped by not exists', $result);
        $this->assertContains('is skipped by no record', $result);
        $this->assertContains('is skipped by has no primary key', $result);
        $this->assertContains('is skipped by no diff', $result);
    }

    /**
     * @test
     */
    function run_no_interaction()
    {
        $result = $this->runApp(array(
            '--rebuild' => '1',
            '-n'        => '1',
            'files'     => array(
                $this->getFile('table.sql'),
                $this->getFile('heavy.sql'),
            )
        ));

        $this->assertContains('more 23 quries', $result);
    }

    /**
     * @test
     */
    function run_misc()
    {
        $this->newSchema->dropAndCreateDatabase($this->old->getDatabase());
        $this->old->exec(file_get_contents($this->getFile('table.sql')));
        $this->old->exec(file_get_contents($this->getFile('data.sql')));
        
        $result = $this->runApp(array(
            'files' => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));
        
        $this->assertContains('no diff schema', $result);
    }

    function test_parseDsn()
    {
        $command = new MigrateCommand();
        $method = new \ReflectionMethod($command, 'parseDsn');
        $method->setAccessible(true);
        $parseDsn = function ($dsn, $default) use ($method, $command) {
            return $method->invoke($command, $dsn, $default);
        };

        $this->assertEquals(array(
            'driver'   => "pdo_mysql",
            'host'     => "host",
            'port'     => 1234,
            'user'     => "user",
            'password' => "pass",
            'dbname'   => "dbname",
        ), $parseDsn('user:pass@host:1234/dbname', array('driver' => 'pdo_mysql')));

        $this->assertEquals(array(
            'driver' => "pdo_mysql",
            'host'   => "host",
            'port'   => 1234,
            'user'   => "user",
            'dbname' => "dbname",
        ), $parseDsn('user@host:1234/dbname', array('driver' => 'pdo_mysql')));

        $this->assertEquals(array(
            'driver' => "pdo_mysql",
            'host'   => "host",
            'port'   => 1234,
            'dbname' => "dbname",
        ), $parseDsn('host:1234/dbname', array('driver' => 'pdo_mysql')));

        $this->assertEquals(array(
            'driver' => "autoscheme",
            'host'   => "host",
            'dbname' => "dbname",
        ), $parseDsn('host', array('dbname' => 'dbname')));
    }
}
