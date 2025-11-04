<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Database add command - Add database backup configuration
 */
final class DatabaseAddCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('database:add')
            ->setDescription('Add database backup configuration for a server')
            ->addArgument('server', InputArgument::OPTIONAL, 'Server name')
            ->setHelp('This command adds database backup configuration (MySQL, PostgreSQL, Elasticsearch, MongoDB) for a server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $io->title('phpBorg - Add Database Backup Configuration');

        try {
            $serverRepo = $this->app->getServerRepository();
            $dbInfoRepo = $this->app->getDatabaseInfoRepository();
            $repoRepo = $this->app->getBorgRepositoryRepository();
            $borgExecutor = $this->app->getBorgExecutor();
            $encryption = $this->app->getEncryptionService();

            // Get server name
            $serverName = $input->getArgument('server');
            if (!$serverName) {
                $question = new Question('Enter server name: ');
                $question->setValidator(function ($answer) {
                    if (empty($answer)) {
                        throw new \RuntimeException('Server name cannot be empty');
                    }
                    return $answer;
                });
                $serverName = $helper->ask($input, $output, $question);
            }

            // Get server
            $server = $serverRepo->findByName($serverName);
            if ($server === null) {
                $io->error("Server '{$serverName}' not found");
                $io->note("Add the server first with: phpborg server:add {$serverName}");
                return Command::FAILURE;
            }

            $io->info("Configuring database backup for: {$server->name}");
            $io->writeln('');

            // Select database type
            $typeQuestion = new ChoiceQuestion(
                'Select database type:',
                ['mysql', 'postgres', 'elasticsearch', 'mongodb'],
                0
            );
            $dbType = $helper->ask($input, $output, $typeQuestion);

            $io->writeln('');
            $io->section("Configuration for {$dbType}");

            $config = match ($dbType) {
                'mysql' => $this->configureMysql($input, $output, $helper, $io),
                'postgres' => $this->configurePostgres($input, $output, $helper, $io),
                'elasticsearch' => $this->configureElasticsearch($input, $output, $helper, $io),
                'mongodb' => $this->configureMongodb($input, $output, $helper, $io),
                default => throw new \RuntimeException("Unsupported database type: {$dbType}"),
            };

            // Display configuration summary
            $io->writeln('');
            $io->section('Configuration Summary');
            $io->table(
                ['Parameter', 'Value'],
                [
                    ['Server', $server->name],
                    ['Database Type', $dbType],
                    ['Host', $config['db_host']],
                    ['Username', $config['db_user']],
                    ['Password', str_repeat('*', strlen($config['db_pass']))],
                    ...(isset($config['vg_name']) ? [['VG Name', $config['vg_name']]] : []),
                    ...(isset($config['lvm_part']) ? [['LVM Partition', $config['lvm_part']]] : []),
                    ['Data Path', $config['data_path']],
                    ['Retention (days)', $config['retention']],
                ]
            );

            $question = new ConfirmationQuestion('Continue with this configuration? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $io->warning('Operation cancelled');
                return Command::FAILURE;
            }

            $io->writeln('');
            $io->section('Creating database backup configuration...');

            // Save database configuration
            $dbInfoId = $dbInfoRepo->create(
                type: $dbType,
                serverId: $server->id,
                dbHost: $config['db_host'],
                dbUser: $config['db_user'],
                dbPassword: $config['db_pass'],
                vgName: $config['vg_name'] ?? '',
                lvmPartition: $config['lvm_part'] ?? '',
                lvSize: $config['lv_size'] ?? '500M',
                dataPath: $config['data_path']
            );

            $io->writeln("✓ Database configuration saved (ID: {$dbInfoId})");

            // Check if repository exists
            $repoPath = $this->app->getConfig()->borgBackupPath . '/' . $server->name . '/' . $dbType;

            if (!is_dir($repoPath)) {
                $io->writeln("✓ Creating Borg repository...");

                mkdir($repoPath, 0755, true);

                $passphrase = $encryption->generatePassphrase();
                $borgExecutor->initRepository($repoPath, $passphrase, 'repokey');

                $repoConfig = parse_ini_file($repoPath . '/config');
                $repoId = $repoConfig['id'];

                $io->writeln("✓ Repository created (ID: {$repoId})");

                // Save repository to database
                $repoRepo->create(
                    serverId: $server->id,
                    repoId: $repoId,
                    type: $dbType,
                    retention: $config['retention'],
                    encryption: 'repokey',
                    passphrase: $passphrase,
                    repoPath: $repoPath,
                    compression: 'lz4',
                    rateLimit: 0,
                    backupPath: $config['data_path'],
                    exclude: null
                );

                // Update db_info with repo_id
                $dbInfoRepo->updateRepositoryId($dbInfoId, $repoId);

                $io->writeln("✓ Repository configuration saved");
            } else {
                $io->writeln("✓ Repository already exists");
            }

            $io->writeln('');
            $io->success("Database backup configuration added successfully!");

            $io->note([
                "To create a backup: phpborg backup {$server->name} --type={$dbType}",
                "To view archives: phpborg list {$server->name} --type={$dbType}",
                "To mount an archive: phpborg mount {$server->name} --type={$dbType}",
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            if ($this->app->getConfig()->isDebug()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Configure MySQL
     */
    private function configureMysql(InputInterface $input, OutputInterface $output, $helper, SymfonyStyle $io): array
    {
        $config = [];

        // VG name
        $question = new Question('VG name (default: vg): ', 'vg');
        $config['vg_name'] = $helper->ask($input, $output, $question);

        // LVM partition
        $question = new Question('LVM partition name (default: root): ', 'root');
        $config['lvm_part'] = $helper->ask($input, $output, $question);

        // LV size for snapshot
        $question = new Question('LVM snapshot size (default: 500M): ', '500M');
        $config['lv_size'] = $helper->ask($input, $output, $question);

        // Database host
        $question = new Question('MySQL host (default: 127.0.0.1): ', '127.0.0.1');
        $config['db_host'] = $helper->ask($input, $output, $question);

        // Database user
        $question = new Question('MySQL username: ');
        $question->setValidator(function ($answer) {
            if (empty($answer)) {
                throw new \RuntimeException('Username cannot be empty');
            }
            return $answer;
        });
        $config['db_user'] = $helper->ask($input, $output, $question);

        // Database password
        $question = new Question('MySQL password: ');
        $question->setHidden(true);
        $question->setValidator(function ($answer) {
            if (empty($answer)) {
                throw new \RuntimeException('Password cannot be empty');
            }
            return $answer;
        });
        $config['db_pass'] = $helper->ask($input, $output, $question);

        // Data path
        $question = new Question('MySQL data path (default: /var/lib/mysql): ', '/var/lib/mysql');
        $config['data_path'] = $helper->ask($input, $output, $question);

        // Retention
        $question = new Question('Retention in days (default: 8): ', '8');
        $config['retention'] = (int)$helper->ask($input, $output, $question);

        return $config;
    }

    /**
     * Configure PostgreSQL
     */
    private function configurePostgres(InputInterface $input, OutputInterface $output, $helper, SymfonyStyle $io): array
    {
        $config = [];

        // Database host
        $question = new Question('PostgreSQL host (default: 127.0.0.1): ', '127.0.0.1');
        $config['db_host'] = $helper->ask($input, $output, $question);

        // Database user
        $question = new Question('PostgreSQL username (default: postgres): ', 'postgres');
        $config['db_user'] = $helper->ask($input, $output, $question);

        // Database password
        $question = new Question('PostgreSQL password: ');
        $question->setHidden(true);
        $config['db_pass'] = $helper->ask($input, $output, $question);

        // Data path
        $question = new Question('PostgreSQL data path (default: /var/lib/postgresql): ', '/var/lib/postgresql');
        $config['data_path'] = $helper->ask($input, $output, $question);

        // Retention
        $question = new Question('Retention in days (default: 8): ', '8');
        $config['retention'] = (int)$helper->ask($input, $output, $question);

        return $config;
    }

    /**
     * Configure Elasticsearch
     */
    private function configureElasticsearch(InputInterface $input, OutputInterface $output, $helper, SymfonyStyle $io): array
    {
        $config = [];

        // Host
        $question = new Question('Elasticsearch host (default: http://127.0.0.1): ', 'http://127.0.0.1');
        $config['db_host'] = $helper->ask($input, $output, $question);

        // Port (stored in db_user field)
        $question = new Question('Elasticsearch port (default: 9200): ', '9200');
        $config['db_user'] = $helper->ask($input, $output, $question);

        // Password (optional)
        $question = new Question('Elasticsearch password (optional, press Enter to skip): ', '');
        $config['db_pass'] = $helper->ask($input, $output, $question) ?: 'none';

        // Data path
        $question = new Question('Snapshot repository path (default: /var/lib/elasticsearch/backups): ', '/var/lib/elasticsearch/backups');
        $config['data_path'] = $helper->ask($input, $output, $question);

        // Retention
        $question = new Question('Retention in days (default: 8): ', '8');
        $config['retention'] = (int)$helper->ask($input, $output, $question);

        return $config;
    }

    /**
     * Configure MongoDB
     */
    private function configureMongodb(InputInterface $input, OutputInterface $output, $helper, SymfonyStyle $io): array
    {
        $config = [];

        // Host
        $question = new Question('MongoDB host (default: 127.0.0.1): ', '127.0.0.1');
        $config['db_host'] = $helper->ask($input, $output, $question);

        // Username
        $question = new Question('MongoDB username: ');
        $config['db_user'] = $helper->ask($input, $output, $question);

        // Password
        $question = new Question('MongoDB password: ');
        $question->setHidden(true);
        $config['db_pass'] = $helper->ask($input, $output, $question);

        // Auth database
        $question = new Question('Authentication database (default: admin): ', 'admin');
        $config['data_path'] = $helper->ask($input, $output, $question);

        // Retention
        $question = new Question('Retention in days (default: 8): ', '8');
        $config['retention'] = (int)$helper->ask($input, $output, $question);

        return $config;
    }
}
