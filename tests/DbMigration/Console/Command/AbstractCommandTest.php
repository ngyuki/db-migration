<?php
namespace ryunosuke\Test\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class AbstractCommandTest extends AbstractTestCase
{
    /** @var ConcreteCommand */
    private $command;

    protected $defaultArgs = array(
        '-n' => true,
    );

    protected function setup()
    {
        parent::setUp();

        $this->command = new ConcreteCommand('test');
    }

    function test_choice()
    {
        $input = new ArrayInput(array());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        // default integer
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertEquals(1, $this->command->choice('hoge', array('a', 'b', 'c'), 1));
        $this->assertEquals("hoge [a/B/c]:", $output->fetch());

        // default string
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertEquals(2, $this->command->choice('hoge', array('a', 'b', 'c'), 'c'));
        $this->assertEquals("hoge [a/b/C]:", $output->fetch());

        // select
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('b'));
        $this->assertEquals(1, $this->command->choice('hoge', array('a', 'b', 'c'), 0));
        $this->assertEquals("hoge [A/b/c]:", $output->fetch());

        // foward match
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('cc'));
        $this->assertEquals(2, $this->command->choice('hoge', array('aaa', 'bbb', 'cccc'), 0));
        $this->assertEquals("hoge [Aaa/bbb/cccc]:", $output->fetch());
    }

    function test_choice_exception()
    {
        $input = new ArrayInput(array());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        // empty choises
        $this->assertException(new \InvalidArgumentException('empty'), function () {
            $this->command->choice('hoge', array(''));
        });

        // undefined default integer
        $this->assertException(new \InvalidArgumentException('is undefined'), function () {
            $this->command->choice('hoge', array('a'), 1);
        });

        // undefined default string
        $this->assertException(new \InvalidArgumentException('is undefined'), function () {
            $this->command->choice('hoge', array('a'), 'b');
        });

        // ambiguous forward match
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('aa'));
        $this->assertException(new \UnexpectedValueException('ambiguous'), function () {
            $this->command->choice('hoge', array('aaA', 'aaB'));
        });

        // invalid answer
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('c'));
        $this->assertException(new \UnexpectedValueException('invalid answer'), function () {
            $this->command->choice('hoge', array('a', 'b'));
        });
    }

    function test_confirm()
    {
        $input = new ArrayInput(array());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        // default true
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertTrue($this->command->confirm('hoge', true));
        $this->assertEquals("hoge [Y/n]:", $output->fetch());

        // default false
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertFalse($this->command->confirm('hoge', false));
        $this->assertEquals("hoge [y/N]:", $output->fetch());

        // select
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('y'));
        $this->assertTrue($this->command->confirm('hoge', false));
        $this->assertEquals("hoge [y/N]:", $output->fetch());
    }
}

class ConcreteCommand extends AbstractCommand
{
    public function setInputOutput(InputInterface $input, OutputInterface $output)
    {
        return parent::setInputOutput($input, $output);
    }

    public function choice($message, $choices = array(), $default = 0)
    {
        return parent::choice($message, $choices, $default);
    }

    public function confirm($message, $default = true)
    {
        return parent::confirm($message, $default);
    }
}
