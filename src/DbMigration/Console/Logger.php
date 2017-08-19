<?php
namespace ryunosuke\DbMigration\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Logger
{
    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    private function write($level, $message, $args)
    {
        if ($this->output->getVerbosity() < $level) {
            return;
        }

        if (is_callable($message)) {
            $message = call_user_func_array($message, $args);
        }
        else if ($args) {
            $message = vsprintf($message, $args);
        }

        $this->output->writeln($message);
    }

    public function log($message) { $this->write(OutputInterface::VERBOSITY_NORMAL, $message, array_slice(func_get_args(), 1)); }

    public function info($message) { $this->write(OutputInterface::VERBOSITY_VERBOSE, $message, array_slice(func_get_args(), 1)); }

    public function debug($message) { $this->write(OutputInterface::VERBOSITY_VERY_VERBOSE, $message, array_slice(func_get_args(), 1)); }

    public function trace($message) { $this->write(OutputInterface::VERBOSITY_DEBUG, $message, array_slice(func_get_args(), 1)); }
}
