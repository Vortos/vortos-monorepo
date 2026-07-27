<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Alerts\DependencyInjection\AlertsExtension;
use Vortos\Alerts\Integration\Messaging\QueueBacklogProviderInterface;
use Vortos\Messaging\Integration\Alerts\DbalQueueBacklogProvider;

/**
 * Registers the DBAL queue-backlog provider once every extension has loaded.
 *
 * MessagingExtension::load() gated this on `$container->has(Connection::class)`. The Connection is
 * registered by vortos-persistence's extension, so during load() that is a race — the class being
 * Doctrine's rather than a vortos package's says nothing about who registers the service.
 *
 * It lost that race in production: QueueBacklogAlertSource was registered and consuming a provider
 * that did not exist, so queue-backlog alerting had nothing to read. Alerting that cannot observe
 * its own subject reports healthy for the same reason it reports anything else — silence.
 *
 * interface_exists() stays in the guard and is correct there: whether vortos-alerts is INSTALLED is
 * an autoloader question, answered identically at any point. Only the has() had to move.
 */
final class QueueBacklogProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(QueueBacklogProviderInterface::class)) {
            return; // vortos-alerts is not installed; there is nothing to feed.
        }

        if (!$container->has(Connection::class)) {
            return; // genuinely no database to read a backlog from.
        }

        if ($container->hasDefinition(DbalQueueBacklogProvider::class)) {
            return; // the extension already won its race.
        }

        $outboxTable = $container->hasParameter('vortos.messaging.outbox_table')
            ? (string) $container->getParameter('vortos.messaging.outbox_table')
            : 'messaging_outbox';

        $dlqTable = $container->hasParameter('vortos.messaging.dlq_table')
            ? (string) $container->getParameter('vortos.messaging.dlq_table')
            : 'messaging_dead_letters';

        $container->register(DbalQueueBacklogProvider::class, DbalQueueBacklogProvider::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$outboxTable', $outboxTable)
            ->setArgument('$deadLetterTable', $dlqTable)
            ->addTag(AlertsExtension::BACKLOG_PROVIDER_TAG)
            ->setPublic(false);
    }
}
