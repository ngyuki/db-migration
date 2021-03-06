<?php
namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\DBAL\Schema\View;
use ryunosuke\DbMigration\Console\Command\MigrateCommand;

class MigrateCommandTest extends AbstractTestCase
{
    protected $commandName = 'migrate';

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
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('drptable', 'integer', 'id'));

        $view = new View('simpleview', 'select 1');
        $this->oldSchema->createView($view);

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

        $this->app->add($command);

        $this->defaultArgs = array(
            '--format' => 'none',
            '-n'       => true,
        );
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
    function run_source()
    {
        // use source dbname
        $result = $this->runApp(array(
            '-vvv'     => true,
            '--source' => $this->new->getHost() . '/temporary',
            'files'    => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertContains('temporary is created', $result);

        // use source and schema
        $result = $this->runApp(array(
            '-vvv'     => true,
            '--source' => $this->new->getHost(),
            '--schema' => 'temporary_schema',
            'files'    => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertContains('temporary_schema is created', $result);

        // use auto detect
        $result = $this->runApp(array(
            '-vvv'     => true,
            '--source' => $this->new->getHost(),
            'files'    => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertRegExp('#migration_tests_old_.* is created#', $result);
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
    function run_type_dml_ex()
    {
        $this->assertExceptionMessage("very invalid sql", $this->runApp, array(
            '--type'    => 'dml',
            '--rebuild' => true,
            'files'     => array(
                $this->getFile('table.sql'),
                $this->getFile('invalid.sql')
            )
        ));
    }

    /**
     * @test
     */
    function run_type_data()
    {
        $result = $this->runApp(array(
            '--migration' => $this->getFile('migs'),
            'files'       => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertContains('insert into notexist VALUES(1)', $result);
        $this->assertContains('insert into notexist (id) VALUES(7);', $result);
        $this->assertContains('insert into notexist (id) VALUES(8);', $result);
        $this->assertContains('insert into notexist (id) VALUES(9);', $result);
        $this->assertEquals(array(1, 7, 8, 9, 21, 22), $this->old->executeQuery('select * from notexist')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertEquals(array('aaa.sql', 'bbb.sql', 'ccc.php'), $this->old->executeQuery('select * from migs')->fetchAll(\PDO::FETCH_COLUMN));

        $this->old->executeUpdate('insert into migs values("hoge", "2011-12-24 12:34:56")');
        $result = $this->runApp(array(
            '--migration' => $this->getFile('migs'),
            'files'       => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertNotContains('insert into notexist VALUES(1)', $result);
        $this->assertNotContains('insert into notexist (id) VALUES(7);', $result);
        $this->assertNotContains('insert into notexist (id) VALUES(8);', $result);
        $this->assertNotContains('insert into notexist (id) VALUES(9);', $result);
        $this->assertContains('[2011-12-24 12:34:56] hoge', $result);
        $this->assertEquals(array(1, 7, 8, 9, 21, 22), $this->old->executeQuery('select * from notexist')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertEquals(array('aaa.sql', 'bbb.sql', 'ccc.php'), $this->old->executeQuery('select * from migs')->fetchAll(\PDO::FETCH_COLUMN));

        $result = $this->runApp(array(
            '--migration' => $this->getFile('nodir'),
            'files'       => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertContains('-- no diff data', $result);

        $this->assertExceptionMessage("'invalid query'", $this->runApp, array(
            '--migration' => $this->getFile('migs_invalid'),
            'files'       => array(
                $this->getFile('table.sql'),
            )
        ));
    }

    /**
     * @test
     */
    function run_type_data_choise()
    {
        $this->runApp(array(
            'files' => array(
                $this->getFile('table.sql'),
            )
        ));

        unset($this->defaultArgs['-n']);

        /** @var MigrateCommand $command */
        $command = $this->app->get('dbal:migrate');
        $command->getQuestionHelper()->setInputStream($this->getEchoStream('y', array('n' => 3), 'y'));

        $result = $this->runApp(array(
            '--migration' => $this->getFile('migs'),
            'files'       => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertContains('migs is created', $result);
        $this->assertEquals(array(), $this->old->executeQuery('select * from notexist')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertEquals(array(), $this->old->executeQuery('select * from migs')->fetchAll(\PDO::FETCH_COLUMN));

        /** @var MigrateCommand $command */
        $command = $this->app->get('dbal:migrate');
        $command->getQuestionHelper()->setInputStream($this->getEchoStream(array('p' => 3), 'y'));

        $result = $this->runApp(array(
            '--migration' => $this->getFile('migs'),
            'files'       => array(
                $this->getFile('table.sql'),
            )
        ));
        $this->assertNotContains('migs is created', $result);
        $this->assertEquals(array(), $this->old->executeQuery('select * from notexist')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertEquals(array('aaa.sql', 'bbb.sql', 'ccc.php'), $this->old->executeQuery('select * from migs')->fetchAll(\PDO::FETCH_COLUMN));
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
                'migtable',
                'drptable',
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
        $this->assertNotContains('`drptable`', $result);
    }

    /**
     * @test
     */
    function run_dmltypes()
    {
        $result = $this->runApp(array(
            '-v'          => true,
            '--type'      => 'dml',
            '--no-insert' => true,
            '--no-delete' => true,
            'files'       => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql')
            )
        ));

        $this->assertContains('UPDATE ', $result);
        $this->assertNotContains('INSERT ', $result);
        $this->assertNotContains('DELETE ', $result);
    }

    /**
     * @test
     */
    function run_noview()
    {
        $result = $this->runApp(array(
            '-v'       => true,
            '--noview' => true,
            'files'    => array(
                $this->getFile('table.sql')
            )
        ));

        $this->assertNotContains('simpleview', $result);
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
            '--rebuild' => true,
            'files'     => array(
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
        unset($this->defaultArgs['-n']);

        /** @var MigrateCommand $command */
        $command = $this->app->get('dbal:migrate');
        $command->getQuestionHelper()->setInputStream($this->getEchoStream(array('y' => 100)));

        $result = $this->runApp(array(
            '--rebuild' => '1',
            'files'     => array(
                $this->getFile('table.sql'),
                $this->getFile('heavy.sql'),
            )
        ));

        $this->assertContains('total query count is 1023', $result);
        $this->assertContains("INSERT INTO `sametable` SET `id` = '1024';", $result);
    }

    /**
     * @test
     */
    function run_format()
    {
        $result = $this->runApp(array(
            '--format' => 'pretty',
            '--check'  => true,
            'files'    => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
        $this->assertContains('[0m', $result);

        $result = $this->runApp(array(
            '--format' => 'format',
            '--check'  => true,
            'files'    => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
        $this->assertContains("DELETE FROM \n", $result);

        $result = $this->runApp(array(
            '--format' => 'highlight',
            '--check'  => true,
            'files'    => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
        $this->assertContains('[0m', $result);

        $result = $this->runApp(array(
            '--format' => 'compress',
            '--check'  => true,
            'files'    => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
        $this->assertContains("DELETE FROM `", $result);
    }

    /**
     * @test
     */
    function run_init()
    {
        unset($this->defaultArgs['-n']);

        /** @var MigrateCommand $command */
        $command = $this->app->get('dbal:migrate');
        $command->getQuestionHelper()->setInputStream($this->getEchoStream(array('y' => 100)));
        $result = $this->runApp(array(
            '-v'     => true,
            '-m'     => $this->getFile('migs'),
            '--init' => true,
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
        $this->assertContains("migration_tests_old is dropped", $result);
        $this->assertContains("migration_tests_old is created", $result);
        $this->assertContains("importDDL", $result);
        $this->assertContains("importDML", $result);
        $this->assertContains("attachMigration", $result);
        $this->assertNotContains("diff DDL", $result);
        $this->assertNotContains("diff DML", $result);
        $this->assertTrue($this->oldSchema->tablesExist('migs'));

        /** @var MigrateCommand $command */
        $command = $this->app->get('dbal:migrate');
        $command->getQuestionHelper()->setInputStream($this->getEchoStream(array('y' => 100)));
        $result = $this->runApp(array(
            '-n'     => false,
            '--init' => true,
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
        $this->assertContains("canceled.", $result);

        $this->assertExceptionMessage("can't initialize database if url specified", $this->runApp, array(
            '--init' => true,
            '--dsn'  => $this->new->getHost(),
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
    }

    /**
     * @test
     */
    function run_dryrun()
    {
        $logger = new DebugStack();
        $this->old->getConfiguration()->setSQLLogger($logger);

        $this->runApp(array(
            '--rebuild'   => true,
            '--migration' => $this->getFile('migs'),
            '--check'     => true,
            'files'       => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));

        // if dryrun, old DB queries are "SELECT" only
        foreach ($logger->queries as $query) {
            $this->assertRegExp('#^SELECT|SHOW#i', ltrim($query['sql']));
        }
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
