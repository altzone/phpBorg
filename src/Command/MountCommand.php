<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mount archive command - Interactive mount with bash shell
 */
final class MountCommand extends Command
{
    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mount')
            ->setDescription('Mount a backup archive interactively')
            ->addArgument('server', InputArgument::REQUIRED, 'Server name')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Backup type (backup, mysql, postgres, etc.)', 'backup')
            ->setHelp('This command mounts a backup archive and starts an interactive shell for browsing/restoring files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $serverName = $input->getArgument('server');
        $type = $input->getOption('type');

        $io->title("phpBorg - Mount Backup Archive");

        try {
            $serverRepo = $this->app->getServerRepository();
            $repositoryRepo = $this->app->getBorgRepositoryRepository();
            $archiveRepo = $this->app->getArchiveRepository();
            $mountService = $this->app->getMountService();

            // Get server
            $server = $serverRepo->findByName($serverName);
            if ($server === null) {
                $io->error("Server '{$serverName}' not found");
                return Command::FAILURE;
            }

            // Get repository
            $repository = $repositoryRepo->findByServerAndType($server->id, $type);
            if ($repository === null) {
                $io->error("No {$type} repository found for server '{$serverName}'");
                return Command::FAILURE;
            }

            // Get archives
            $archives = $archiveRepo->findByRepositoryId($repository->repoId);

            if (empty($archives)) {
                $io->warning('No archives found for this server');
                return Command::SUCCESS;
            }

            // Interactive loop
            $continue = true;
            while ($continue) {
                $io->writeln('');
                $io->section('Available Backup Archives');

                // Prepare choices
                $choices = [];
                $archiveMap = [];
                foreach ($archives as $index => $archive) {
                    $label = sprintf(
                        '[%d] %s - %s (%s, %s files)',
                        $index,
                        $archive->end->format('Y-m-d H:i:s'),
                        $archive->name,
                        $this->formatBytes($archive->originalSize),
                        number_format($archive->filesCount)
                    );
                    $choices[$index] = $label;
                    $archiveMap[$index] = $archive;
                }

                $choices['q'] = 'Quit';

                // Ask user to choose
                $question = new ChoiceQuestion(
                    'Select backup to mount (or q to quit):',
                    $choices,
                    'q'
                );
                $question->setErrorMessage('Invalid selection');

                $answer = $helper->ask($input, $output, $question);

                if ($answer === 'Quit' || !isset($archiveMap[(int)array_search($answer, $choices)])) {
                    $continue = false;
                    $io->writeln('Goodbye!');
                    continue;
                }

                $selectedIndex = (int)array_search($answer, $choices);
                $selectedArchive = $archiveMap[$selectedIndex];

                $io->writeln('');
                $io->info("Selected: {$selectedArchive->name}");
                $io->writeln('');

                // Mount and start interactive shell
                try {
                    $exitCode = $mountService->mountArchiveInteractive(
                        $repository,
                        $selectedArchive,
                        $server
                    );

                    $io->writeln('');
                    $io->success('Backup session ended');

                    // Ask if user wants to mount another
                    if (!$io->confirm('Mount another backup?', false)) {
                        $continue = false;
                    }

                } catch (\Exception $e) {
                    $io->error("Failed to mount archive: {$e->getMessage()}");

                    if ($this->app->getConfig()->isDebug()) {
                        $io->writeln($e->getTraceAsString());
                    }

                    if (!$io->confirm('Try another archive?', true)) {
                        $continue = false;
                    }
                }
            }

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
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
