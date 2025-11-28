<?php

declare(strict_types=1);

namespace PhpBorg\Command;

use PhpBorg\Application;
use PhpBorg\Service\Queue\Handlers\DeployAgentKeyHandler;
use PhpBorg\Service\Queue\Handlers\RefreshAgentAuthorizedKeysHandler;
use PhpBorg\Service\Queue\Worker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Worker dédié pour les opérations sur les agents
 *
 * Ce worker tourne sur la queue "agent" et peut utiliser sudo
 * pour les opérations nécessitant des privilèges élevés.
 */
final class AgentWorkerStartCommand extends Command
{
    private const QUEUE_NAME = 'agent';

    public function __construct(
        private readonly Application $app
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('agent-worker:start')
            ->setDescription('Démarre le worker dédié aux opérations agents (déploiement clés, certificats)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Démarrage du worker agent phpBorg...</info>');
        $output->writeln('Queue: ' . self::QUEUE_NAME);
        $output->writeln('');

        // Vérifier que sudo est disponible
        if (!$this->checkSudoAccess()) {
            $output->writeln('<error>ERREUR: sudo non disponible ou non configuré pour phpborg</error>');
            $output->writeln('<comment>Assurez-vous que /etc/sudoers.d/phpborg-agent est configuré</comment>');
            return Command::FAILURE;
        }

        $output->writeln('<info>✓ Accès sudo vérifié</info>');

        // Services depuis le conteneur DI
        $jobQueue = $this->app->getJobQueue();
        $logger = $this->app->getLogger();

        // Créer le worker
        $worker = new Worker($jobQueue, $logger, 'agent');

        // Enregistrer les handlers spécifiques aux agents
        // Le port SSH Borg est configuré dans les settings, valeur par défaut 2222
        $worker->registerHandler('deploy_agent_key', new DeployAgentKeyHandler(
            $this->app->getCertificateManager(),
            $this->app->getAgentRepository(),
            $logger,
            2222
        ));

        // Handler pour rafraîchir les authorized_keys (appelé par BackupCreateHandler)
        $worker->registerHandler('refresh_agent_authorized_keys', new RefreshAgentAuthorizedKeysHandler(
            $this->app->getAgentRepository(),
            $logger
        ));

        $output->writeln('<comment>Worker agent prêt. En attente de jobs...</comment>');
        $output->writeln('<comment>Appuyez sur Ctrl+C pour arrêter</comment>');
        $output->writeln('');

        // Démarrer le worker (bloquant)
        $worker->start(self::QUEUE_NAME);

        return Command::SUCCESS;
    }

    /**
     * Vérifier que sudo fonctionne pour les opérations nécessaires
     */
    private function checkSudoAccess(): bool
    {
        // Test simple: vérifier qu'on peut lister /var/lib/phpborg-borg
        $output = [];
        $returnCode = 0;
        exec('sudo -n ls /var/lib/phpborg-borg 2>&1', $output, $returnCode);

        return $returnCode === 0;
    }
}
