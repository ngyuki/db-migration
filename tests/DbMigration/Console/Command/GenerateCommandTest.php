<?php
namespace ryunosuke\Test\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Command\GenerateCommand;

class GenerateCommandTest extends AbstractTestCase
{
    protected $commandName = 'generate';

    protected function setup()
    {
        parent::setUp();

        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('gentable', 'integer', 'id', 'code'));

        $this->old->insert('gentable', array(
            'id'   => 1,
            'code' => 10
        ));

        $command = new GenerateCommand();

        $this->app->add($command);
    }

    /**
     * @test
     */
    function run_ddl()
    {
        $createfile = self::$tmpdir . '/create.sql';
        !file_exists($createfile) or unlink($createfile);

        $result = $this->runApp(array(
            'files' => array(
                str_replace('\\', '/', $createfile),
            )
        ));

        $this->assertEquals('', $result);
        $this->assertFileExists($createfile);
    }

    /**
     * @test
     */
    function run_ddl_vvv()
    {
        $createfile = self::$tmpdir . '/create.sql';
        !file_exists($createfile) or unlink($createfile);

        $result = $this->runApp(array(
            '-vvv'  => true,
            'files' => array(
                str_replace('\\', '/', $createfile),
            )
        ));

        $this->assertContains('CREATE TABLE gentable', $result);
        $this->assertFileExists($createfile);
    }

    /**
     * @test
     */
    function run_dml()
    {
        $result = $this->runApp(array(
            'files' => array(
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.sql'),
            )
        ));

        $this->assertEquals('', $result);
        $this->assertFileContains("INSERT INTO `gentable` (`id`, `code`) VALUES ('1', '10')", self::$tmpdir . '/gentable.sql');
    }

    /**
     * @test
     */
    function run_dml_vvv()
    {
        $result = $this->runApp(array(
            '-vvv'  => true,
            'files' => array(
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.sql'),
            )
        ));

        $this->assertContains("INSERT INTO `gentable` (`id`, `code`) VALUES ('1', '10')", $result);
        $this->assertFileContains("INSERT INTO `gentable` (`id`, `code`) VALUES ('1', '10')", self::$tmpdir . '/gentable.sql');
    }

    /**
     * @test
     */
    function run_dml_where()
    {
        $this->runApp(array(
            '--where' => 'gentable.id=-1',
            'files'   => array(
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.sql'),
            )
        ));

        $this->assertStringEqualsFile(self::$tmpdir . '/gentable.sql', "\n");
    }

    /**
     * @test
     */
    function run_dml_ignore()
    {
        $this->runApp(array(
            '--ignore' => 'gentable.id',
            'files'    => array(
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.sql'),
            )
        ));

        $this->assertFileContains("INSERT INTO `gentable` (`code`, `id`) VALUES ('10', '0')", self::$tmpdir . '/gentable.sql', "\n");
    }

    /**
     * @test
     */
    function run_data()
    {
        $result = $this->runApp(array(
            '--migration'  => 'gentable',
            'files'    => array(
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
            )
        ));

        $this->assertEquals('', $result);
        $this->assertFileNotContains('gentable', self::$tmpdir . '/table.sql');
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
}
