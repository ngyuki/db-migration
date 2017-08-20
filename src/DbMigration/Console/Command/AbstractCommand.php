<?php
namespace ryunosuke\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

abstract class AbstractCommand extends Command
{
    /** @var QuestionHelper */
    private $questionHelper;

    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    /** @var Logger */
    protected $logger;

    public function getQuestionHelper()
    {
        return $this->questionHelper ?: $this->questionHelper = new QuestionHelper();
    }

    protected function setInputOutput(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->logger = new Logger($input, $output);
    }

    protected function confirm($message, $default = true)
    {
        $autoyes = $this->input->getOption('check') || $this->input->getOption('no-interaction');
        if ($autoyes) {
            return true;
        }

        $yesno = $default ? 'Y/n' : 'y/N';
        $question = new Question("<question>{$message}[{$yesno}]:</question>", $default ? 'y' : 'n');

        return 'y' === strtolower($this->getQuestionHelper()->doAsk($this->output, $question));
    }
}
