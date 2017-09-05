<?php
namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class AbstractTestCase extends \ryunosuke\Test\DbMigration\AbstractTestCase
{
    /**
     * @var Application
     */
    protected $app;

    protected $commandName;

    protected $defaultArgs = array();

    protected function setup()
    {
        parent::setUp();

        $helperSet = new HelperSet(array(
            'db' => new ConnectionHelper($this->old)
        ));

        $this->app = new Application('Test');
        $this->app->setCatchExceptions(false);
        $this->app->setAutoExit(false);
        $this->app->setHelperSet($helperSet);
    }

    protected function getFile($filename)
    {
        if ($filename !== null) {
            $filename = "\\_files\\$filename";
        }
        return str_replace('\\', '/', __DIR__ . $filename);
    }

    protected function getEchoStream()
    {
        $stream = fopen('php://memory', 'w+');
        foreach (func_get_args() as $arg) {
            if (is_array($arg)) {
                $l = reset($arg);
                $c = key($arg);
                fwrite($stream, str_repeat($c . PHP_EOL, $l));
            }
            else {
                fwrite($stream, $arg . PHP_EOL);
            }
        }
        rewind($stream);
        return $stream;
    }

    /**
     * @closurable
     * @param array $inputArray
     * @return string
     */
    protected function runApp($inputArray)
    {
        $inputArray = array(
                'command' => 'dbal:' . $this->commandName
            ) + $inputArray + $this->defaultArgs;

        $input = new ArrayInput($inputArray);
        $output = new BufferedOutput();

        $this->app->run($input, $output);

        return $output->fetch();
    }
}
