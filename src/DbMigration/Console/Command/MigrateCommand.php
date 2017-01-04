<?php
namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Console\CancelException;
use ryunosuke\DbMigration\MigrationException;
use ryunosuke\DbMigration\Migrator;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MigrateCommand extends Command
{
    private $questionHelper = null;

    private $preMigration = null;

    private $postMigration = null;

    public function getQuestionHelper()
    {
        return $this->questionHelper ?: $this->questionHelper = new QuestionHelper();
    }

    public function setPreMigration($callback)
    {
        ASSERT('is_callable($callback)');

        $this->preMigration = $callback;
    }

    public function setPostMigration($callback)
    {
        ASSERT('is_callable($callback)');

        $this->postMigration = $callback;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('dbal:migrate')->setDescription('Migrate to SQL file.');
        $this->setDefinition(array(
            new InputArgument('files', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'SQL files'),
            new InputOption('target', null, InputOption::VALUE_OPTIONAL, 'Specify target DSN (default cli-config)'),
            new InputOption('dsn', 'd', InputOption::VALUE_OPTIONAL, 'Specify destination DSN (default create temporary database) suffix based on cli-config'),
            new InputOption('schema', 's', InputOption::VALUE_OPTIONAL, 'Specify destination database name (default `md5(filemtime(files))`)'),
            new InputOption('type', 't', InputOption::VALUE_OPTIONAL, 'Migration SQL type (ddl, dml. default both)'),
            new InputOption('noview', null, InputOption::VALUE_NONE, 'No migration View.'),
            new InputOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Target tables pattern (enable comma separated value)'),
            new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Except tables pattern (enable comma separated value)'),
            new InputOption('where', 'w', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Where condition.'),
            new InputOption('ignore', 'g', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore column for DML.'),
            new InputOption('format', null, InputOption::VALUE_OPTIONAL, 'Format output SQL (none, pretty, format, highlight or compress. default pretty)', 'pretty'),
            new InputOption('omit', 'o', InputOption::VALUE_REQUIRED, 'Omit size for long SQL'),
            new InputOption('csv-encoding', null, InputOption::VALUE_OPTIONAL, 'Specify CSV encoding.', 'SJIS-win'),
            new InputOption('check', 'c', InputOption::VALUE_NONE, 'Check only (Dry run. force no-interaction)'),
            new InputOption('force', 'f', InputOption::VALUE_NONE, 'Force continue, ignore errors'),
            new InputOption('rebuild', 'r', InputOption::VALUE_NONE, 'Rebuild destination database'),
            new InputOption('keep', 'k', InputOption::VALUE_NONE, 'Not drop destination database'),
            new InputOption('init', null, InputOption::VALUE_NONE, 'Initialize database (Too Dangerous)'),
        ));
        $this->setHelp(<<<EOT
Migrate to SQL file or running database.
 e.g. `dbal:migrate example.sql`
 e.g. `dbal:migrate example.sql --include foo`
 e.g. `dbal:migrate example.sql --exclude bar`
 e.g. `dbal:migrate example.sql --where kind=2`
 e.g. `dbal:migrate example.sql --check`
 e.g. `dbal:migrate -d hostname/dbname --check`
EOT
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(var_export($input->getArguments(), true));
            $output->writeln(var_export($input->getOptions(), true));
        }

        // normalize file
        $files = $this->normalizeFile($input);

        // get target Connection
        $srcConn = $this->readySource($input, $output);

        // migrate
        try {
            // create destination database and connection
            $dstConn = $this->readyDestination($this->getHelper('db')->getConnection(), $files, $input, $output);

            // if init flag, task is completed at this point
            if ($input->getOption('init')) {
                return;
            }

            // pre migration
            $this->doCallback(1, $srcConn);

            // DDL
            $this->migrateDDL($srcConn, $dstConn, $input, $output);

            // DML
            $this->migrateDML($srcConn, $dstConn, $input, $output);

            // clean destination database and connection
            $this->cleanDestination($srcConn, $dstConn, $input, $output);

            // post migration
            $this->doCallback(9, $srcConn);
        }
        catch (CancelException $e) {
            // post migration
            $this->doCallback(9, $srcConn);

            $output->writeln("<comment>" . $e->getMessage() . "</comment>");
        }
        catch (\Exception $e) {
            // post migration
            $this->doCallback(9, $srcConn);

            throw $e;
        }
    }

    private function normalizeFile(InputInterface $input)
    {
        $files = (array) $input->getArgument('files');
        if (count($files) === 0 && !$input->getOption('dsn')) {
            throw new \InvalidArgumentException("require 'file' argument or 'dsn' option.");
        }

        $result = array();

        foreach ($files as $file) {
            $filePath = realpath($file);

            if (false === $filePath) {
                $filePath = $file;
            }

            if (!is_readable($filePath)) {
                throw new \InvalidArgumentException(sprintf("SQL file '<info>%s</info>' does not exist.", $filePath));
            }
            elseif (is_dir($filePath)) {
                throw new \InvalidArgumentException(sprintf("SQL file '<info>%s</info>' is directory.", $filePath));
            }

            $result[] = $filePath;
        }

        return $result;
    }

    private function parseDsn($dsn, $default)
    {
        // pre-fix
        if (strpos($dsn, '://') === false) {
            $dsn = 'autoscheme://' . $dsn;
        }

        $parseDatabaseUrl = new \ReflectionMethod('\\Doctrine\\DBAL\\DriverManager', 'parseDatabaseUrl');
        $parseDatabaseUrl->setAccessible(true);
        $dstParams = $parseDatabaseUrl->invoke(null, array('url' => $dsn));
        unset($dstParams['url']);

        // fix driver (if $driver is "autoscheme", use default driver)
        if ($dstParams['driver'] === 'autoscheme' && isset($default['driver'])) {
            $dstParams['driver'] = $default['driver'];
        }

        // fix dbname (if $dbname is empty, use default name)
        if ((!isset($dstParams['dbname']) || $dstParams['dbname'] === '') && isset($default['dbname'])) {
            $dstParams['dbname'] = $default['dbname'];
        }

        return $dstParams + $default;
    }

    private function readySource(InputInterface $input, OutputInterface $output)
    {
        /* @var $srcConn \Doctrine\DBAL\Connection */
        $srcConn = $this->getHelper('db')->getConnection();
        $target = $input->getOption('target');

        // no target, return cli-config connection
        if (!$target) {
            return $srcConn;
        }

        $srcParams = $srcConn->getParams();
        unset($srcParams['url']);
        $srcParams = $this->parseDsn($target, $srcParams);
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(var_export($srcParams, true));
        }
        return DriverManager::getConnection($srcParams);
    }

    private function readyDestination(Connection $srcConn, $files, InputInterface $input, OutputInterface $output)
    {
        $srcParams = $srcConn->getParams();
        unset($srcParams['url']);

        $init = $input->getOption('init');
        $rebuild = $input->getOption('rebuild') || $init;
        $url = $input->getOption('dsn');
        $autoyes = $input->getOption('no-interaction');
        $confirm = $this->getQuestionHelper();

        // can't initialize
        if ($init && $url) {
            throw new \InvalidArgumentException("can't initialize database if url specified.");
        }

        if ($init && !$autoyes && 'n' === strtolower($confirm->doAsk($output, new Question("<question>specified init option. really?(y/N):</question>", 'n')))) {
            throw new CancelException('canceled.');
        }

        if ($url) {
            // detect destination database params
            $dstParams = $this->parseDsn($url, $srcParams);
        }
        else {
            $dstParams = $srcParams;
            $schema = $input->getOption('schema');
            if ($init) {
                $schema = $srcParams['dbname'];
            }
            if ($schema) {
                $dstParams['dbname'] = $schema;
            }
            else {
                $dstParams['dbname'] = $srcParams['dbname'] . '_' . md5(implode('', array_map('filemtime', $files)));
            }
        }

        // create destination connection
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(var_export($dstParams, true));
        }
        $dstConn = DriverManager::getConnection($dstParams);

        // if specify DSN, never touch destination
        if (!$url) {
            $dstName = $dstParams['dbname'];
            unset($dstParams['dbname']);

            $schemer = DriverManager::getConnection($dstParams)->getSchemaManager();
            $existsDstDb = in_array($dstName, $schemer->listDatabases());

            // drop destination database if exists
            if ($existsDstDb && $rebuild) {
                $schemer->dropDatabase($dstName);
                $output->writeln("-- <info>$dstName</info> <comment>is dropped.</comment>");
                $existsDstDb = false;
            }

            // create destination database if not exists
            if (!$existsDstDb) {
                $schemer->createDatabase($dstName);
                $this->doCallback(1, $dstConn);
                $output->writeln("-- <info>$dstName</info> <comment>is created.</comment>");

                // import sql files from argument
                $transporter = new Transporter($dstConn);
                $transporter->enableView(!$input->getOption('noview'));
                $transporter->setEncoding('csv', $input->getOption('csv-encoding'));
                $ddlfile = array_shift($files);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln("-- <info>importDDL</info> $ddlfile");
                }
                $sqls = $transporter->importDDL($ddlfile);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    foreach ($sqls as $sql) {
                        $this->writeSql($input, $output, $sql);
                    }
                }
                $dstConn->beginTransaction();
                try {
                    foreach ($files as $filename) {
                        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                            $output->writeln("-- <info>importDML</info> $filename");
                        }
                        $rows = $transporter->importDML($filename);
                        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                            foreach ($rows as $row) {
                                $output->writeln(var_export($row, true));
                            }
                        }
                    }
                    $dstConn->commit();
                }
                catch (\Exception $ex) {
                    $dstConn->rollBack();
                    throw $ex;
                }
            }
            else {
                $this->doCallback(1, $dstConn);
            }
        }

        return $dstConn;
    }

    private function cleanDestination(Connection $srcConn, Connection $dstConn, InputInterface $input, OutputInterface $output)
    {
        $autoyes = $input->getOption('no-interaction');
        $keepdb = $input->getOption('dsn') || $input->getOption('keep');
        $confirm = $this->getQuestionHelper();

        // drop destination database
        if (!$keepdb) {
            $dstName = $dstConn->getDatabase();

            // drop current
            $schemer = $dstConn->getSchemaManager();
            $schemer->dropDatabase($dstName);
            $output->writeln("-- <info>$dstName</info> <comment>is dropped.</comment>");

            // drop garbage
            $target = $srcConn->getDatabase();
            foreach ($schemer->listDatabases() as $database) {
                if (preg_match("/^{$target}_[0-9a-f]{32}$/", $database)) {
                    if ($autoyes || 'n' !== strtolower($confirm->doAsk($output, new Question("<question>drop '$database'(this probably is garbage)?(y/N):</question>", 'n')))) {
                        $schemer->dropDatabase($database);
                        $output->writeln("-- <info>$database</info> <comment>is dropped.</comment>");
                    }
                }
            }
        }

        $this->doCallback(9, $dstConn);
    }

    private function migrateDDL(Connection $srcConn, Connection $dstConn, InputInterface $input, OutputInterface $output)
    {
        if (!in_array($input->getOption('type'), explode(',', ',ddl'))) {
            return;
        }

        $dryrun = $input->getOption('check');
        $autoyes = $dryrun || $input->getOption('no-interaction');
        $force = $input->getOption('force');

        $includes = (array) $input->getOption('include');
        $excludes = (array) $input->getOption('exclude');
        $noview = $input->getOption('noview');

        $confirm = $this->getQuestionHelper();

        $output->writeln("-- <comment>diff DDL</comment>");

        // get ddl
        $sqls = Migrator::getDDL($srcConn, $dstConn, $includes, $excludes, $noview);
        if (!$sqls) {
            $output->writeln("-- no diff schema.");
            return;
        }

        foreach ($sqls as $sql) {
            // display sql(formatted)
            $this->writeSql($input, $output, $sql);

            // exec if noconfirm or confirm answer is "y"
            if ($autoyes || 'n' !== strtolower($confirm->doAsk($output, new Question('<question>exec this query?(y/N):</question>', 'n')))) {
                if (!$dryrun) {
                    try {
                        $srcConn->exec($sql);
                    }
                    catch (\Exception $e) {
                        $output->writeln('/* <error>' . $e->getMessage() . '</error> */');
                        if (!$force && ($autoyes || 'n' !== strtolower($confirm->doAsk($output, new Question('<question>exit?(y/N):</question>', 'n'))))) {
                            throw $e;
                        }
                    }
                }
            }
        }
    }

    private function migrateDML(Connection $srcConn, Connection $dstConn, InputInterface $input, OutputInterface $output)
    {
        if (!in_array($input->getOption('type'), explode(',', ',dml'))) {
            return;
        }

        $dryrun = $input->getOption('check');
        $autoyes = $dryrun || $input->getOption('no-interaction');
        $force = $input->getOption('force');

        $includes = (array) $input->getOption('include');
        $excludes = (array) $input->getOption('exclude');
        $wheres = (array) $input->getOption('where') ?: array();
        $ignores = (array) $input->getOption('ignore') ?: array();

        $confirm = $this->getQuestionHelper();

        $output->writeln("-- <comment>diff DML</comment>");

        $tables = $dstConn->getSchemaManager()->listTableNames();
        $maxlength = $tables ? max(array_map('strlen', $tables)) + 1 : 0;
        $dmlflag = false;
        foreach ($tables as $table) {
            $title = sprintf("<info>%-{$maxlength}s</info>", $table);

            // skip to not contain include tables
            $flag = count($includes) > 0;
            foreach ($includes as $target) {
                foreach (array_map('trim', explode(',', $target)) as $regex) {
                    if (preg_match("@$regex@", $table)) {
                        $flag = false;
                        break;
                    }
                }
            }
            if ($flag) {
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln("-- $title is skipped by include option.");
                }
                continue;
            }

            // skip to contain exclude tables
            foreach ($excludes as $except) {
                foreach (array_map('trim', explode(',', $except)) as $regex) {
                    if (preg_match("@$regex@", $table)) {
                        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                            $output->writeln("-- $title is skipped by exclude option.");
                        }
                        continue 3;
                    }
                }
            }

            // skip to not exists tables
            if (!$srcConn->getSchemaManager()->tablesExist($table)) {
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln("-- $title is skipped by not exists.");
                }
                continue;
            }

            // skip no has record
            if (!$dstConn->fetchColumn("select COUNT(*) from $table")) {
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln("-- $title is skipped by no record.");
                }
                continue;
            }

            // get dml
            $sqls = null;
            try {
                $sqls = Migrator::getDML($srcConn, $dstConn, $table, $wheres, $ignores);
            }
            catch (MigrationException $ex) {
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln("-- $title is skipped by " . $ex->getMessage());
                }
                continue;
            }

            if (!$sqls) {
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln("-- $title is skipped by no diff.");
                }
                continue;
            }

            $output->writeln("-- $title has diff:");

            // display sql(if noconfirm, max 1000)
            $shown_sqls = $sqls;
            if ($autoyes && count($sqls) > 1000) {
                $shown_sqls = array_slice($sqls, 0, 1000);
                $shown_sqls[] = 'more ' . (count($sqls) - 1000) . ' quries.';
            }
            foreach ($shown_sqls as $sql) {
                $this->writeSql($input, $output, $sql);
            }

            // exec if noconfirm or confirm answer is "y"
            $dmlflag = true;
            if ($autoyes || 'n' !== strtolower($confirm->doAsk($output, new Question('<question>exec this query?(Y/n):</question>', 'y')))) {
                if (!$dryrun) {
                    $srcConn->beginTransaction();

                    try {
                        foreach ($sqls as $sql) {
                            $srcConn->exec($sql);
                        }
                        $srcConn->commit();
                    }
                    catch (\Exception $e) {
                        $srcConn->rollBack();

                        $output->writeln('/* <error>' . $e->getMessage() . '</error> */');
                        if (!$force && ($autoyes || 'n' !== strtolower($confirm->doAsk($output, new Question('<question>exit?(y/N):</question>', 'n'))))) {
                            throw $e;
                        }
                    }
                }
            }
        }
        if (!$dmlflag) {
            $output->writeln("-- no diff table.");
        }
    }

    private function doCallback($timing, Connection $srcConn)
    {
        if ($timing === 1) {
            if (is_callable($this->preMigration)) {
                call_user_func($this->preMigration, $srcConn);
            }
        }
        if ($timing === 9) {
            if (is_callable($this->postMigration)) {
                call_user_func($this->postMigration, $srcConn);
            }
        }
    }

    private function writeSql(InputInterface $input, OutputInterface $output, $sql)
    {
        if ($output->getVerbosity() <= OutputInterface::VERBOSITY_QUIET) {
            return;
        }

        $sql .= ';';
        switch ($input->getOption('format')) {
            case 'pretty':
                $sql = \SqlFormatter::format($sql, true);
                break;
            case 'format':
                $sql = \SqlFormatter::format($sql, false);
                break;
            case 'highlight':
                $sql = \SqlFormatter::highlight($sql);
                break;
            case 'compress':
                $sql = \SqlFormatter::compress($sql);
                break;
        }

        $omitlength = intval($input->getOption('omit')) ?: 1024;
        if (mb_strlen($sql) > $omitlength) {
            $sql = mb_strimwidth($sql, 0, $omitlength, PHP_EOL . "...(omitted)");
        }

        $output->write($sql);
        $output->writeln('');
    }
}
