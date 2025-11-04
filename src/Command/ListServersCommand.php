<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List servers command
 */
final class ListServersCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('server:list')
            ->setDescription('List all backup servers')
            ->setHelp('This command lists all servers configured in the backup system');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('phpBorg - Backup Servers');

        try {
            $serverRepo = $this->app->getServerRepository();
            $servers = $serverRepo->findAll();

            if (empty($servers)) {
                $io->warning('No servers configured');
                $io->note('Add a server with: phpborg server:add <name>');
                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($servers as $server) {
                $rows[] = [
                    $server->id,
                    $server->name,
                    $server->host,
                    $server->port,
                    $server->backupType,
                    $server->active ? '✓' : '✗',
                ];
            }

            $io->table(
                ['ID', 'Name', 'Host', 'Port', 'Type', 'Active'],
                $rows
            );

            $io->writeln(sprintf('Total: %d server(s)', count($servers)));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
