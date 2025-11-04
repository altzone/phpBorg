<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Add server command
 */
final class AddServerCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('server:add')
            ->setDescription('Add a new server to backup system')
            ->addArgument('name', InputArgument::OPTIONAL, 'Server name/hostname')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'SSH port', 22)
            ->addOption('retention', 'r', InputOption::VALUE_OPTIONAL, 'Retention days', 8)
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type (internal/external)', 'internal')
            ->setHelp('This command adds a new server to the backup system');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $io->title('phpBorg - Add New Server');

        // Get server name
        $name = $input->getArgument('name');
        if (!$name) {
            $question = new Question('Enter server name/hostname: ');
            $name = $helper->ask($input, $output, $question);
        }

        if (!$name) {
            $io->error('Server name is required');
            return Command::FAILURE;
        }

        $port = (int)$input->getOption('port');
        $retention = (int)$input->getOption('retention');
        $type = $input->getOption('type');

        // Display configuration
        $io->section('Server Configuration');
        $io->table(
            ['Parameter', 'Value'],
            [
                ['Name', $name],
                ['SSH Port', $port],
                ['Retention (days)', $retention],
                ['Type', $type],
            ]
        );

        // Confirm
        $question = new ConfirmationQuestion('Continue with this configuration? [y/N] ', false);
        if (!$helper->ask($input, $output, $question)) {
            $io->warning('Operation cancelled');
            return Command::FAILURE;
        }

        $io->writeln('');
        $io->section('Setting up server...');

        try {
            $serverManager = $this->app->getServerManager();

            $io->writeln('Testing SSH connection...');
            $serverId = $serverManager->addServer($name, $port, $retention, $type);

            $io->success("Server '{$name}' added successfully!");
            $io->writeln("Server ID: {$serverId}");

            $io->note([
                'The server is now ready for backup.',
                "Use: phpborg backup {$name}",
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to add server: {$e->getMessage()}");
            if ($this->app->getConfig()->isDebug()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
