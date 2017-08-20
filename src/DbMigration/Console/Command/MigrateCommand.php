<?php
namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use ryunosuke\DbMigration\Console\CancelException;
use ryunosuke\DbMigration\MigrationException;
use ryunosuke\DbMigration\Migrator;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends AbstractCommand
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
            new InputOption('source', null, InputOption::VALUE_OPTIONAL, 'Specify source DSN (default cli-config, temporary database)'),
            new InputOption('dsn', 'd', InputOption::VALUE_OPTIONAL, 'Specify destination DSN (default create temporary database) suffix based on cli-config'),
            new InputOption('schema', 's', InputOption::VALUE_OPTIONAL, 'Specify destination database name (default `md5(filemtime(files))`)'),
            new InputOption('type', 't', InputOption::VALUE_OPTIONAL, 'Migration SQL type (ddl, dml. default both)'),
            new InputOption('noview', null, InputOption::VALUE_NONE, 'No migration View.'),
            new InputOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Target tables pattern (enable comma separated value)'),
            new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Except tables pattern (enable comma separated value)'),
            new InputOption('where', 'w', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Where condition.'),
            new InputOption('ignore', 'g', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore column for DML.'),
            new InputOption('no-insert', null, InputOption::VALUE_NONE, 'Not contains INSERT DML'),
            new InputOption('no-delete', null, InputOption::VALUE_NONE, 'Not contains DELETE DML'),
            new InputOption('no-update', null, InputOption::VALUE_NONE, 'Not contains UPDATE DML'),
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
        $this->setInputOutput($input, $output);

        $this->logger->trace('var_export', $this->input->getArguments(), true);
        $this->logger->trace('var_export', $this->input->getOptions(), true);

        // normalize file
        $files = $this->normalizeFile();

        // get target Connection
        $srcConn = $this->readySource();

        // migrate
        try {
            // create destination database and connection
            $dstConn = $this->readyDestination($this->getHelper('db')->getConnection(), $files);

            // if init flag, task is completed at this point
            if ($this->input->getOption('init')) {
                return;
            }

            // pre migration
            $this->doCallback(1, $srcConn);

            // DDL
            $this->migrateDDL($srcConn, $dstConn);

            // DML
            $this->migrateDML($srcConn, $dstConn);

            // clean destination database and connection
            $this->cleanDestination($srcConn, $dstConn);

            // post migration
            $this->doCallback(9, $srcConn);
        }
        catch (CancelException $e) {
            // post migration
            $this->doCallback(9, $srcConn);

            $this->logger->log("<comment>" . $e->getMessage() . "</comment>");
        }
        catch (\Exception $e) {
            // post migration
            $this->doCallback(9, $srcConn);

            throw $e;
        }
    }

    private function normalizeFile()
    {
        $files = (array) $this->input->getArgument('files');
        if (count($files) === 0 && !$this->input->getOption('dsn')) {
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

    private function readySource()
    {
        /* @var $srcConn \Doctrine\DBAL\Connection */
        $srcConn = $this->getHelper('db')->getConnection();
        $target = $this->input->getOption('target');

        // no target, return cli-config connection
        if (!$target) {
            return $srcConn;
        }

        $srcParams = $srcConn->getParams();
        unset($srcParams['url']);
        $srcParams = $this->parseDsn($target, $srcParams);
        $this->logger->trace('var_export', $srcParams, true);
        return DriverManager::getConnection($srcParams);
    }

    private function readyDestination(Connection $srcConn, $files)
    {
        $srcParams = $srcConn->getParams();
        unset($srcParams['url']);

        $init = $this->input->getOption('init');
        $rebuild = $this->input->getOption('rebuild') || $init;
        $source = $this->input->getOption('source');
        $url = $this->input->getOption('dsn');

        // can't initialize
        if ($init && $url) {
            throw new \InvalidArgumentException("can't initialize database if url specified.");
        }

        if ($init && !$this->confirm('specified init option. really?', false)) {
            throw new CancelException('canceled.');
        }

        if ($url) {
            // detect destination database params
            $dstParams = $this->parseDsn($url, $srcParams);
        }
        else {
            if ($source) {
                $tmpParams = $srcParams;
                unset($tmpParams['dbname']);
                $dstParams = $this->parseDsn($source, $tmpParams);
            }
            else {
                $dstParams = $srcParams;
                unset($dstParams['dbname']);
            }

            $schema = $this->input->getOption('schema');
            if ($init) {
                $dstParams['dbname'] = $srcParams['dbname'];
            }
            else if ($schema) {
                $dstParams['dbname'] = $schema;
            }
            else if (!isset($dstParams['dbname'])) {
                $dstParams['dbname'] = $srcParams['dbname'] . '_' . md5(implode('', array_map('filemtime', $files)));
            }
        }

        // create destination connection
        $this->logger->trace('var_export', $dstParams, true);
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
                $this->logger->log("-- <info>$dstName</info> <comment>is dropped.</comment>");
                $existsDstDb = false;
            }

            // create destination database if not exists
            if (!$existsDstDb) {
                $schemer->createDatabase($dstName);
                $this->doCallback(1, $dstConn);
                $this->logger->log("-- <info>$dstName</info> <comment>is created.</comment>");

                // import sql files from argument
                $transporter = new Transporter($dstConn);
                $transporter->enableView(!$this->input->getOption('noview'));
                $transporter->setEncoding('csv', $this->input->getOption('csv-encoding'));
                $ddlfile = array_shift($files);
                $this->logger->info("-- <info>importDDL</info> $ddlfile");
                $sqls = $transporter->importDDL($ddlfile);
                foreach ($sqls as $sql) {
                    $this->logger->debug(array($this, 'formatSql'), $sql);
                }
                $dstConn->beginTransaction();
                try {
                    foreach ($files as $filename) {
                        $this->logger->info("-- <info>importDML</info> $ddlfile");
                        $rows = $transporter->importDML($filename);
                        foreach ($rows as $row) {
                            $this->logger->debug('var_export', $row, true);
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

    private function cleanDestination(Connection $srcConn, Connection $dstConn)
    {
        $keepdb = $this->input->getOption('dsn') || $this->input->getOption('keep');

        // drop destination database
        if (!$keepdb) {
            $dstName = $dstConn->getDatabase();

            // drop current
            $schemer = $dstConn->getSchemaManager();
            $schemer->dropDatabase($dstName);
            $this->logger->log("-- <info>$dstName</info> <comment>is dropped.</comment>");

            // drop garbage
            $target = $srcConn->getDatabase();
            foreach ($schemer->listDatabases() as $database) {
                if (preg_match("/^{$target}_[0-9a-f]{32}$/", $database)) {
                    if ($this->confirm("drop '$database'(this probably is garbage)?", false)) {
                        $schemer->dropDatabase($database);
                        $this->logger->log("-- <info>$database</info> <comment>is dropped.</comment>");
                    }
                }
            }
        }

        $this->doCallback(9, $dstConn);
    }

    private function migrateDDL(Connection $srcConn, Connection $dstConn)
    {
        if (!in_array($this->input->getOption('type'), explode(',', ',ddl'))) {
            return;
        }

        $dryrun = $this->input->getOption('check');
        $force = $this->input->getOption('force');

        $includes = (array) $this->input->getOption('include');
        $excludes = (array) $this->input->getOption('exclude');
        $noview = $this->input->getOption('noview');

        $this->logger->log("-- <comment>diff DDL</comment>");

        // get ddl
        $sqls = Migrator::getDDL($srcConn, $dstConn, $includes, $excludes, $noview);
        if (!$sqls) {
            $this->logger->log("-- no diff schema.");
            return;
        }

        $execed = false;
        foreach ($sqls as $sql) {
            // display sql(formatted)
            $this->logger->log(array($this, 'formatSql'), $sql);

            // exec if noconfirm or confirm answer is "y"
            if ($this->confirm('exec this query?', false)) {
                if (!$dryrun) {
                    try {
                        $srcConn->exec($sql);
                        $execed = true;
                    }
                    catch (\Exception $e) {
                        $this->logger->log('/* <error>' . $e->getMessage() . '</error> */');
                        if (!$force && $this->confirm('exit?', false)) {
                            throw $e;
                        }
                    }
                }
            }
        }

        // reconnect if exec ddl. for recreate schema (Migrator::getSchema)
        if ($execed) {
            Migrator::setSchema($srcConn, null);
        }
    }

    private function migrateDML(Connection $srcConn, Connection $dstConn)
    {
        if (!in_array($this->input->getOption('type'), explode(',', ',dml'))) {
            return;
        }

        $dryrun = $this->input->getOption('check');
        $autoyes = $dryrun || $this->input->getOption('no-interaction');
        $force = $this->input->getOption('force');

        $includes = (array) $this->input->getOption('include');
        $excludes = (array) $this->input->getOption('exclude');
        $wheres = (array) $this->input->getOption('where') ?: array();
        $ignores = (array) $this->input->getOption('ignore') ?: array();

        $dmltypes = array(
            'insert' => !$this->input->getOption('no-insert'),
            'delete' => !$this->input->getOption('no-delete'),
            'update' => !$this->input->getOption('no-update'),
        );

        $this->logger->log("-- <comment>diff DML</comment>");

        $dsttables = Migrator::getSchema($dstConn)->getTables();
        $maxlength = $dsttables ? max(array_map(function (Table $table) { return strlen($table->getName()); }, $dsttables)) + 1 : 0;
        $dmlflag = false;
        foreach ($dsttables as $table) {
            $tablename = $table->getName();
            $title = sprintf("<info>%-{$maxlength}s</info>", $tablename);

            $filtered = Migrator::filterTable($tablename, $includes, $excludes);
            if ($filtered === 1) {
                $this->logger->info("-- $title is skipped by include option.");
                continue;
            }
            else if ($filtered === 2) {
                $this->logger->info("-- $title is skipped by exclude option.");
                continue;
            }

            // skip to not exists tables
            if (!Migrator::getSchema($srcConn)->hasTable($tablename)) {
                $this->logger->info("-- $title is skipped by not exists.");
                continue;
            }

            // skip no has record
            if (!$dstConn->fetchColumn("select COUNT(*) from $tablename")) {
                $this->logger->info("-- $title is skipped by no record.");
                continue;
            }

            // get dml
            $sqls = null;
            try {
                $sqls = Migrator::getDML($srcConn, $dstConn, $tablename, $wheres, $ignores, $dmltypes);
            }
            catch (MigrationException $ex) {
                $this->logger->info("-- $title is skipped by " . $ex->getMessage());
                continue;
            }

            if (!$sqls) {
                $this->logger->info("-- $title is skipped by no diff.");
                continue;
            }

            $this->logger->log("-- $title has diff:");

            // display sql(if noconfirm, max 1000)
            $shown_sqls = $sqls;
            if ($autoyes && count($sqls) > 1000) {
                $shown_sqls = array_slice($sqls, 0, 1000);
                $shown_sqls[] = 'more ' . (count($sqls) - 1000) . ' quries.';
            }
            foreach ($shown_sqls as $sql) {
                $this->logger->log(array($this, 'formatSql'), $sql);
            }

            // exec if noconfirm or confirm answer is "y"
            $dmlflag = true;
            if ($this->confirm('exec this query?', true)) {
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

                        $this->logger->log('/* <error>' . $e->getMessage() . '</error> */');
                        if (!$force && $this->confirm('exit?', false)) {
                            throw $e;
                        }
                    }
                }
            }
        }
        if (!$dmlflag) {
            $this->logger->log("-- no diff table.");
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

    public function formatSql($sql)
    {
        $sql .= ';';
        switch ($this->input->getOption('format')) {
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

        $omitlength = intval($this->input->getOption('omit')) ?: 1024;
        if (mb_strlen($sql) > $omitlength) {
            $sql = mb_strimwidth($sql, 0, $omitlength, PHP_EOL . "...(omitted)");
        }

        return $sql;
    }
}
