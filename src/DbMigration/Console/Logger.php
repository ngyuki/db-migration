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

    /**
     * write log
     *
     * @param int $level OutputInterface::VERBOSITY_XXX
     * @param string|callable $message log message
     * @param array $args vsprintf args or callable args
     * @return string|null written message or null
     */
    private function write($level, $message, $args)
    {
        if ($this->output->getVerbosity() < $level) {
            return null;
        }

        if (is_callable($message)) {
            $message = call_user_func_array($message, $args);
        }
        else if ($args) {
            $message = vsprintf($message, $args);
        }

        $this->output->writeln($message);
        return $message;
    }

    /**
     * write log VERBOSITY_NORMAL (write when except for "-q")
     *
     * @param string|callable $message log message
     * @return string|null
     */
    public function log($message)
    {
        return $this->write(OutputInterface::VERBOSITY_NORMAL, $message, array_slice(func_get_args(), 1));
    }

    /**
     * write log VERBOSITY_VERBOSE (write when specify "-v")
     *
     * @param string|callable $message log message
     * @return string|null
     */
    public function info($message)
    {
        return $this->write(OutputInterface::VERBOSITY_VERBOSE, $message, array_slice(func_get_args(), 1));
    }

    /**
     * write log VERBOSITY_VERY_VERBOSE (write when specify "-vv")
     *
     * @param string|callable $message log message
     * @return string|null
     */
    public function debug($message)
    {
        return $this->write(OutputInterface::VERBOSITY_VERY_VERBOSE, $message, array_slice(func_get_args(), 1));
    }

    /**
     * write log VERBOSITY_DEBUG (write when specify "-vvv")
     *
     * @param string|callable $message log message
     * @return string|null
     */
    public function trace($message)
    {
        return $this->write(OutputInterface::VERBOSITY_DEBUG, $message, array_slice(func_get_args(), 1));
    }
}
