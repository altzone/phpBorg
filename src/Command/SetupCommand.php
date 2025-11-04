<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use PhpBorg\Service\Setup\SetupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Setup command - Verify and install phpBorg
 */
final class SetupCommand extends Command
{
    private ?Application $app = null;

    public function __construct(?Application $app = null)
    {
        $this->app = $app;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('setup')
            ->setDescription('Check and setup phpBorg installation')
            ->addOption('fix', 'f', InputOption::VALUE_NONE, 'Attempt to fix problems automatically')
            ->addOption('install-borg', null, InputOption::VALUE_NONE, 'Install BorgBackup if missing')
            ->setHelp('This command checks all requirements and can fix common installation issues');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');
        $fix = $input->getOption('fix');
        $installBorg = $input->getOption('install-borg');

        $io->title('phpBorg 2.0 - Setup & Installation Check');

        // Try to load application (may fail if .env doesn't exist)
        $setupService = null;
        try {
            if ($this->app !== null) {
                $setupService = new SetupService($this->app->getConfig(), $this->app->getLogger());
            } else {
                $setupService = new SetupService();
            }
        } catch (\Exception $e) {
            // Config may not be loaded yet
            $setupService = new SetupService();
        }

        $io->section('Running System Checks...');

        // Run all checks
        $checks = $setupService->runAllChecks();

        // Display results
        $this->displayChecks($io, $checks);

        // Summary
        $io->writeln('');
        $io->section('Summary');

        $passed = count(array_filter($checks, fn($c) => $c['status'] === 'ok'));
        $warnings = count(array_filter($checks, fn($c) => $c['status'] === 'warning'));
        $errors = count(array_filter($checks, fn($c) => $c['status'] === 'error'));
        $total = count($checks);

        $io->writeln(sprintf(
            'Total checks: %d | Passed: <info>%d</info> | Warnings: <comment>%d</comment> | Errors: <error>%d</error>',
            $total,
            $passed,
            $warnings,
            $errors
        ));

        // Auto-fix section
        if ($errors > 0 || $warnings > 0) {
            $io->writeln('');

            if ($fix || $installBorg) {
                $io->section('Attempting to Fix Issues...');
                $fixed = $this->autoFix($io, $setupService, $checks, $installBorg);

                if ($fixed) {
                    $io->writeln('');
                    $io->info('Re-running checks...');
                    $checks = $setupService->runAllChecks();
                    $this->displayChecks($io, $checks);
                }
            } else {
                $io->note([
                    'Some checks failed. You can:',
                    '  1. Run with --fix to attempt automatic fixes',
                    '  2. Run with --install-borg to install BorgBackup',
                    '  3. Fix issues manually (see messages above)',
                ]);
            }
        }

        // Final verdict
        $io->writeln('');
        if ($setupService->allRequiredChecksPassed()) {
            $io->success('✓ All required checks passed! phpBorg is ready to use.');
            $io->writeln('');
            $io->writeln('Next steps:');
            $io->listing([
                'Add a server: ./bin/phpborg server:add <server-name>',
                'Configure database backup: ./bin/phpborg database:add <server-name>',
                'Create first backup: ./bin/phpborg backup <server-name>',
                'List all commands: ./bin/phpborg list',
            ]);
            return Command::SUCCESS;
        } else {
            $io->error('✗ Some required checks failed. Please fix the issues above.');
            return Command::FAILURE;
        }
    }

    /**
     * Display check results
     */
    private function displayChecks(SymfonyStyle $io, array $checks): void
    {
        $rows = [];
        foreach ($checks as $name => $check) {
            $status = match ($check['status']) {
                'ok' => '<info>✓ OK</info>',
                'warning' => '<comment>⚠ WARNING</comment>',
                'error' => '<error>✗ ERROR</error>',
                default => '<comment>?</comment>',
            };

            $required = $check['required'] ? '<comment>Required</comment>' : 'Optional';

            $rows[] = [
                $this->formatCheckName($name),
                $status,
                $required,
                $check['message'],
            ];
        }

        $io->table(
            ['Check', 'Status', 'Type', 'Message'],
            $rows
        );
    }

    /**
     * Format check name
     */
    private function formatCheckName(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }

    /**
     * Attempt automatic fixes
     */
    private function autoFix(SymfonyStyle $io, SetupService $setupService, array $checks, bool $installBorg): bool
    {
        $fixed = false;
        $helper = $this->getHelper('question');

        // Fix .env file
        if (isset($checks['env_file']) && $checks['env_file']['status'] !== 'ok') {
            $io->write('Creating .env file from .env.example... ');
            if ($setupService->createEnvFile()) {
                $io->writeln('<info>✓ Done</info>');
                $io->warning('Please edit .env and configure your database credentials!');
                $io->writeln('Run: nano .env');
                $fixed = true;
            } else {
                $io->writeln('<error>✗ Failed</error>');
            }
        }

        // Fix directories
        if (isset($checks['directories']) && $checks['directories']['status'] === 'error') {
            if (str_contains($checks['directories']['message'], 'Missing directories')) {
                $question = new ConfirmationQuestion('Create missing directories? [Y/n] ', true);
                if ($helper->ask(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\ConsoleOutput(), $question)) {
                    $io->write('Creating directories... ');
                    if ($this->app && $setupService->createDirectories()) {
                        $io->writeln('<info>✓ Done</info>');
                        $fixed = true;
                    } else {
                        $io->writeln('<error>✗ Failed</error>');
                    }
                }
            }
        }

        // Install BorgBackup
        if (isset($checks['borg_binary']) && $checks['borg_binary']['status'] === 'error' && $installBorg) {
            $io->writeln('');
            $io->writeln('<comment>Installing BorgBackup...</comment>');
            $io->writeln('This requires sudo privileges and may take a few minutes.');

            if ($setupService->installBorgBackup()) {
                $io->writeln('<info>✓ BorgBackup installed successfully</info>');
                $fixed = true;
            } else {
                $io->writeln('<error>✗ Failed to install BorgBackup automatically</error>');
                $io->writeln('Please install manually:');
                $io->listing([
                    'Debian/Ubuntu: sudo apt-get install borgbackup',
                    'RedHat/CentOS: sudo yum install borgbackup',
                    'Fedora: sudo dnf install borgbackup',
                    'Or download: https://github.com/borgbackup/borg/releases',
                ]);
            }
        }

        return $fixed;
    }
}
