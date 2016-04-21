<?php
namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Generator;
use ryunosuke\DbMigration\MigrationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
{
    private $preMigration = null;

    private $postMigration = null;

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
            new InputOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Target tables (enable comma separated value)'),
            new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Except tables (enable comma separated value)'),
            new InputOption('where', 'w', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Where condition.'),
            new InputOption('ignore', 'g', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore column for DML.'),
            new InputOption('omit', 'o', InputOption::VALUE_REQUIRED, 'Omit size for long SQL'),
            new InputOption('check', 'c', InputOption::VALUE_NONE, 'Check only (Dry run. force no-interaction)'),
            new InputOption('force', 'f', InputOption::VALUE_NONE, 'Force continue, ignore errors'),
            new InputOption('rebuild', 'r', InputOption::VALUE_NONE, 'Rebuild destination database'),
            new InputOption('keep', 'k', InputOption::VALUE_NONE, 'Not drop destination database')
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
            $dstConn = $this->readyDestination($srcConn, $files, $input, $output);
            
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
        $dstParams = $this->parseDsn($target, $srcParams);
        $dstConn = DriverManager::getConnection($dstParams);
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(var_export($dstParams, true));
        }

        return $dstConn;
    }

    private function readyDestination(Connection $srcConn, $files, InputInterface $input, OutputInterface $output)
    {
        $srcParams = $srcConn->getParams();
        unset($srcParams['url']);
        
        $url = $input->getOption('dsn');
        if ($url) {
            // detect destination database params
            $dstParams = $this->parseDsn($url, $srcParams);
        }
        else {
            $dstParams = $srcParams;
            $schema = $input->getOption('schema');
            if ($schema) {
                $dstParams['dbname'] = $schema;
            }
            else {
                $dstParams['dbname'] = $srcParams['dbname'] . '_' . md5(implode('', array_map('filemtime', $files)));
            }
        }
        
        // create destination connection
        $dstConn = DriverManager::getConnection($dstParams);
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(var_export($dstParams, true));
        }
        
        // if specify DSN, never touch destination
        if (!$url) {
            $dstName = $dstParams['dbname'];
            unset($dstParams['dbname']);
            
            $schemer = DriverManager::getConnection($dstParams)->getSchemaManager();
            $existsDstDb = in_array($dstName, $schemer->listDatabases());
            
            // drop destination database if exists
            if ($existsDstDb && $input->getOption('rebuild')) {
                $schemer->dropDatabase($dstName);
                $output->writeln("-- <info>$dstName</info> <comment>is dropped.</comment>");
                $existsDstDb = false;
            }
            
            // create destination database if not exists
            if (!$existsDstDb) {
                $schemer->createDatabase($dstName);
                $this->doCallback(1, $dstConn);
                
                // import sql files from argument
                foreach ($files as $filename) {
                    $dstConn->beginTransaction();
                    
                    try {
                        $dstConn->exec(file_get_contents($filename));
                        $dstConn->commit();
                    }
                    catch (\Exception $e) {
                        $dstConn->rollBack();
                        throw $e;
                    }
                }
                
                $output->writeln("-- <info>$dstName</info> <comment>is created.</comment>");
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
        $dialog = new DialogHelper();
        
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
                    if ($autoyes || $dialog->askConfirmation($output, "<question>drop '$database'(this probably is garbage)?(y/n):</question>", false)) {
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
        
        $dialog = new DialogHelper();
        
        $output->writeln("-- <comment>diff DDL</comment>");
        
        // get ddl
        $sqls = Generator::getDDL($srcConn, $dstConn, $includes, $excludes);
        if (!$sqls) {
            $output->writeln("-- no diff schema.");
            return;
        }
        
        foreach ($sqls as $sql) {
            // display sql(formatted)
            $this->writeSql($input, $output, $sql, true, ";");
            
            // exec if noconfirm or confirm answer is "y"
            if ($autoyes || $dialog->askConfirmation($output, '<question>exec this query?(y/n):</question>', false)) {
                if (!$dryrun) {
                    try {
                        $srcConn->exec($sql);
                    }
                    catch (\Exception $e) {
                        if ($force) {
                            $output->writeln('-- <error>' . $e->getMessage() . '</error>');
                        }
                        else {
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
        
        $dialog = new DialogHelper();
        
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
                    $output->writeln("-- $title is skipped by target option.");
                }
                continue;
            }
            
            // skip to contain exclude tables
            foreach ($excludes as $except) {
                foreach (array_map('trim', explode(',', $except)) as $regex) {
                    if (preg_match("@$regex@", $table)) {
                        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                            $output->writeln("-- $title is skipped by except option.");
                        }
                        continue 3;
                    }
                }
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
                $sqls = Generator::getDML($srcConn, $dstConn, $table, $wheres, $ignores);
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
                $this->writeSql($input, $output, $sql, false, ";");
            }
            
            // exec if noconfirm or confirm answer is "y"
            $dmlflag = true;
            if ($autoyes || $dialog->askConfirmation($output, '<question>exec this query?(y/n):</question>', true)) {
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
                        
                        if ($force) {
                            $output->writeln('-- <error>' . $e->getMessage() . '</error>');
                        }
                        else {
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

    private function writeSql(InputInterface $input, OutputInterface $output, $sql, $formatting, $delimiter)
    {
        if ($output->getVerbosity() <= OutputInterface::VERBOSITY_QUIET) {
            return;
        }
        
        $omitlength = intval($input->getOption('omit')) ?: 1024;
        
        if ($formatting) {
            $sql = preg_replace('/(CREATE (TABLE|(UNIQUE )?INDEX).+?\()(.+)/su', "$1\n  $4", $sql);
            $sql = preg_replace('/(CREATE (TABLE|(UNIQUE )?INDEX).+)(\))/su', "$1\n$4", $sql);
            
            $sql = preg_replace('/(ALTER TABLE .+?) ((ADD|DROP|CHANGE|MODIFY).+)/su', "$1\n  $2", $sql);
            
            $sql = preg_replace('/(, )([^\\d])/u', ",\n  $2", $sql);
        }
        
        if (mb_strlen($sql) > $omitlength) {
            $sql = mb_strimwidth($sql, 0, $omitlength, PHP_EOL . "...(omitted)");
        }
        
        $output->write($sql);
        $output->writeln($delimiter);
        $output->writeln('');
    }
}

