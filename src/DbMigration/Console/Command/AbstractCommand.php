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

    protected function choice($message, $choices = array(), $default = 0)
    {
        // filter and check $choices
        $choices = array_filter((array) $choices, 'strlen');
        if (!$choices) {
            throw new \InvalidArgumentException('$choices is empty.');
        }

        // detect default value
        if (is_int($default)) {
            if (!isset($choices[$default])) {
                throw new \InvalidArgumentException("default index '$default' is undefined.");
            }
            $default = $choices[$default];
        }
        $defkey = array_search($default, $choices);
        if ($defkey === false) {
            throw new \InvalidArgumentException("default value '$default' is undefined.");
        }

        // default value to ucfirst
        $choices[$defkey] = ucfirst($choices[$defkey]);

        // question
        $selection = implode('/', $choices);
        $question = new Question("<question>{$message} [{$selection}]:</question>", $default);
        $answer = $this->getQuestionHelper()->ask($this->input, $this->output, $question);

        // return answer index
        $return = null;
        foreach ($choices as $index => $choice) {
            if (stripos($choice, $answer) === 0) {
                if (isset($return)) {
                    throw new \UnexpectedValueException("ambiguous forward match.");
                }
                $return = $index;
            }
        }
        if (!isset($return)) {
            throw new \UnexpectedValueException("'$answer' is invalid answer.");
        }
        return $return;
    }

    protected function confirm($message, $default = true)
    {
        return $this->choice($message, array('y', 'n'), $default ? 0 : 1) === 0;
    }
}
