<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use PhpBorg\Service\Backup\RepositoryImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import an existing Borg repository into phpBorg (Bug #7 / F2).
 *
 * phpBorg previously had no way to register a pre-existing Borg repository: repositories
 * were only ever created by the backup workflow. This command attaches an existing repo
 * (encrypted or not) by creating the server/storage-pool/repository rows and then syncing
 * its archives so they appear in the UI.
 *
 * Example:
 *   phpborg repository:import --path=/mnt/repo-20t/chronoborg --encryption=none \
 *     --server=chronoborg --fix-ownership
 */
final class RepositoryImportCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('repository:import')
            ->setDescription('Import an existing Borg repository (register it and sync its archives)')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Absolute path to the existing Borg repository')
            ->addOption('encryption', 'e', InputOption::VALUE_REQUIRED, "Repository encryption mode ('none', 'repokey', 'keyfile', ...)", 'none')
            ->addOption('passphrase', null, InputOption::VALUE_REQUIRED, 'Passphrase (required for encrypted repositories)', '')
            ->addOption('server', 's', InputOption::VALUE_REQUIRED, 'Server name to attach the repository to (created if missing)')
            ->addOption('pool', null, InputOption::VALUE_REQUIRED, 'Storage pool name (created from the repo parent dir if missing)')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Backup type', 'backup')
            ->addOption('compression', 'c', InputOption::VALUE_REQUIRED, 'Compression', 'lz4')
            ->addOption('keep-daily', null, InputOption::VALUE_REQUIRED, 'Retention: daily', '7')
            ->addOption('keep-weekly', null, InputOption::VALUE_REQUIRED, 'Retention: weekly', '4')
            ->addOption('keep-monthly', null, InputOption::VALUE_REQUIRED, 'Retention: monthly', '6')
            ->addOption('keep-yearly', null, InputOption::VALUE_REQUIRED, 'Retention: yearly', '0')
            ->addOption('fix-ownership', null, InputOption::VALUE_NONE, 'chown -R the repo to phpborg-borg (needed if not already accessible)')
            ->addOption('no-sync', null, InputOption::VALUE_NONE, 'Do not sync archives after import')
            ->setHelp('Registers an existing Borg repository and imports its archives into phpBorg.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('phpBorg - Import Existing Borg Repository');

        $path = rtrim((string)$input->getOption('path'), '/');
        $encryption = strtolower(trim((string)$input->getOption('encryption')));
        $passphrase = (string)$input->getOption('passphrase');
        $type = (string)$input->getOption('type');
        $compression = (string)$input->getOption('compression');

        // --- Validate the path is a Borg repository -------------------------------
        if ($path === '') {
            $io->error('--path is required (absolute path to the Borg repository)');
            return Command::FAILURE;
        }
        if ($path[0] !== '/') {
            $io->error("--path must be absolute: {$path}");
            return Command::FAILURE;
        }
        if (!is_dir($path)) {
            $io->error("Path does not exist or is not a directory: {$path}");
            return Command::FAILURE;
        }
        if (!is_file($path . '/config') || !is_dir($path . '/data')) {
            $io->error("Not a Borg repository (missing config or data/): {$path}");
            return Command::FAILURE;
        }
        if ($encryption !== 'none' && $passphrase === '') {
            $io->error("Encrypted repository ({$encryption}) requires --passphrase");
            return Command::FAILURE;
        }

        // --- Read the Borg repository id from its config --------------------------
        $repoConfig = @parse_ini_file($path . '/config');
        $repoId = is_array($repoConfig) ? ($repoConfig['id'] ?? '') : '';
        if (!is_string($repoId) || $repoId === '') {
            $io->error("Could not read repository id from {$path}/config");
            return Command::FAILURE;
        }
        $io->writeln("✓ Detected Borg repository id: <info>{$repoId}</info>");

        try {
            $importService = new RepositoryImportService(
                $this->app->getBorgRepositoryRepository(),
                $this->app->getServerRepository(),
                $this->app->getStoragePoolRepository(),
                $this->app->getBackupService(),
                $this->app->getLogger()
            );

            if ($input->getOption('fix-ownership')) {
                $io->writeln('→ Fixing ownership (chown -R phpborg-borg) - this may take a while on large repos...');
            }
            $io->writeln('→ Registering repository and syncing archives...');

            $result = $importService->import([
                'path' => $path,
                'encryption' => $encryption,
                'passphrase' => $passphrase,
                'server' => $input->getOption('server'),
                'pool' => $input->getOption('pool'),
                'type' => $type,
                'compression' => $compression,
                'keepDaily' => (int)$input->getOption('keep-daily'),
                'keepWeekly' => (int)$input->getOption('keep-weekly'),
                'keepMonthly' => (int)$input->getOption('keep-monthly'),
                'keepYearly' => (int)$input->getOption('keep-yearly'),
                'fixOwnership' => (bool)$input->getOption('fix-ownership'),
                'sync' => !$input->getOption('no-sync'),
            ]);

            if ($result['already']) {
                $io->warning("Repository {$result['repo_id']} is already registered. Nothing to do.");
                return Command::SUCCESS;
            }

            $io->success("Repository registered (id {$result['repo_id']}, server_id {$result['server_id']}, encryption: {$encryption})");

            if ($input->getOption('no-sync')) {
                $io->note("Skipped archive sync (--no-sync). Run: phpborg sync-archives --server-id={$result['server_id']} --type={$type}");
                return Command::SUCCESS;
            }

            $io->writeln("  Archives imported: <info>{$result['synced']}</info>");
            $io->writeln("  Errors: <comment>{$result['errors']}</comment>");

            if ($result['errors'] > 0) {
                $io->warning('Some archives failed to import. Check the logs (often an ownership/permission issue - retry with --fix-ownership).');
                return Command::FAILURE;
            }

            $io->success("Import complete. {$result['synced']} archive(s) now visible in phpBorg.");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Import failed: {$e->getMessage()}");
            if ($this->app->getConfig()->isDebug()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
