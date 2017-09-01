<?php
namespace ryunosuke\Test\DbMigration\Console;

use ryunosuke\DbMigration\Console\Logger;
use ryunosuke\Test\DbMigration\AbstractTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class LoggerTest extends AbstractTestCase
{
    public function test_level()
    {
        $output = new BufferedOutput();
        $logger = new Logger(new ArrayInput(array()), $output);

        $all = function (Logger $logger) {
            $logger->log('_log');
            $logger->info('_info');
            $logger->debug('_debug');
            $logger->trace('_trace');
            return $logger;
        };

        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $all($logger);
        $this->assertEquals([""], explode(PHP_EOL, $output->fetch()));

        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        $all($logger);
        $this->assertEquals(["_log", ""], explode(PHP_EOL, $output->fetch()));

        $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        $all($logger);
        $this->assertEquals(["_log", "_info", ""], explode(PHP_EOL, $output->fetch()));

        $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $all($logger);
        $this->assertEquals(["_log", "_info", "_debug", ""], explode(PHP_EOL, $output->fetch()));

        $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        $all($logger);
        $this->assertEquals(["_log", "_info", "_debug", "_trace", ""], explode(PHP_EOL, $output->fetch()));
    }

    public function test_write()
    {
        $output = new BufferedOutput();
        $logger = new Logger(new ArrayInput(array()), $output);

        $logger->log('string');
        $this->assertEquals("string", trim($output->fetch()));

        $logger->log('sprintf%04d%s', 123, 'string');
        $this->assertEquals("sprintf0123string", trim($output->fetch()));

        $logger->log('json_encode', array(123, 's' => 'string'));
        $this->assertEquals('{"0":123,"s":"string"}', trim($output->fetch()));
    }
}
