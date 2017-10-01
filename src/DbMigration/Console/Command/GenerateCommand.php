<?php
namespace ryunosuke\DbMigration\Console\Command;

use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('dbal:generate')->setDescription('Generate to Record file.');
        $this->setDefinition(array(
            new InputArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Definitation files. First argument is meaned schema.'),
            new InputOption('noview', null, InputOption::VALUE_NONE, 'No migration View.'),
            new InputOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Target tables pattern (enable comma separated value)'),
            new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Except tables pattern (enable comma separated value)'),
            new InputOption('migration', 'm', InputOption::VALUE_OPTIONAL, 'Specify migration directory.'),
            new InputOption('where', 'w', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Where condition.'),
            new InputOption('ignore', 'g', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore column.'),
            new InputOption('table-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for tables.', null),
            new InputOption('view-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for views.', null),
            new InputOption('csv-encoding', null, InputOption::VALUE_OPTIONAL, 'Specify CSV encoding.', 'SJIS-win'),
            new InputOption('yml-inline', null, InputOption::VALUE_OPTIONAL, 'Specify YML inline nest level.', 4),
            new InputOption('yml-indent', null, InputOption::VALUE_OPTIONAL, 'Specify YML indent size.', 4),
        ));
        $this->setHelp(<<<EOT
Generate to SQL file baseed on extension.
 e.g. `dbal:generate table.sql record.yml`
 e.g. `dbal:generate table.sql record.yml --where t_table.column=1`
 e.g. `dbal:generate table.sql record.yml --ignore t_table.column`
EOT
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setInputOutput($input, $output);

        $this->logger->trace('var_export', $this->input->getArguments(), true);
        $this->logger->trace('var_export', $this->input->getOptions(), true);

        // normalize file
        $files = $this->normalizeFile();

        // option
        $includes = (array) $this->input->getOption('include');
        $excludes = (array) $this->input->getOption('exclude');
        if ($this->input->getOption('migration')) {
            $excludes[] = '^' . basename($this->input->getOption('migration')) . '$';
        }
        $wheres = (array) $this->input->getOption('where') ?: array();
        $ignores = (array) $this->input->getOption('ignore') ?: array();

        // get target Connection
        $conn = $this->getHelper('db')->getConnection();

        // export sql files from argument
        $transporter = new Transporter($conn);
        $transporter->enableView(!$this->input->getOption('noview'));
        $transporter->setEncoding('csv', $this->input->getOption('csv-encoding'));
        $transporter->setDirectory('table', $this->input->getOption('table-directory'));
        $transporter->setDirectory('view', $this->input->getOption('view-directory'));
        $transporter->setYmlOption('inline', $this->input->getOption('yml-inline'));
        $transporter->setYmlOption('indent', $this->input->getOption('yml-indent'));
        $ddl = $transporter->exportDDL(array_shift($files), $includes, $excludes);
        $this->logger->info($ddl);
        foreach ($files as $filename) {
            $dml = $transporter->exportDML($filename, $wheres, $ignores);
            $this->logger->info($dml);
        }
    }

    private function normalizeFile()
    {
        $files = (array) $this->input->getArgument('files');

        $result = array();

        foreach ($files as $file) {
            $filePath = realpath($file);

            if (false === $filePath) {
                $filePath = $file;
            }

            if (is_dir($filePath)) {
                throw new \InvalidArgumentException(sprintf("Record file '<info>%s</info>' is directory.", $filePath));
            }

            $result[] = $filePath;
        }

        return $result;
    }
}
