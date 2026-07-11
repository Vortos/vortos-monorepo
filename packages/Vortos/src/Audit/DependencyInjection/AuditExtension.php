<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Action\AuditActionProviderInterface;
use Vortos\Audit\Action\AuditActionRegistry;
use Vortos\Audit\AuditTrail;
use Vortos\Audit\AuditTrailInterface;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\DependencyInjection\Compiler\AuditActionProviderPass;
use Vortos\Audit\Recorder\NullAuditRecorder;

/**
 * Wires the audit domain core (P1).
 *
 * Storage (P2), async ingestion (P3), retention (P4), query/export (P5) attach their
 * own recorder/reader services and re-alias AuditRecorderInterface as they are added;
 * until then the Null recorder keeps AuditTrailInterface callable (and loud) everywhere.
 */
final class AuditExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_audit';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $strict = $container->hasParameter('vortos_audit.strict')
            ? (bool) $container->getParameter('vortos_audit.strict')
            : true;

        // Any AuditActionProviderInterface impl is auto-tagged; the compiler pass folds
        // them all into the registry.
        $container->registerForAutoconfiguration(AuditActionProviderInterface::class)
            ->addTag(AuditActionProviderInterface::TAG);

        $container->register(AuditActionRegistry::class, AuditActionRegistry::class)
            ->setArgument('$providers', []) // filled by AuditActionProviderPass
            ->setPublic(false);

        // Default sink: Null recorder (logs a warning) until a storage backend re-aliases
        // AuditRecorderInterface. Registered only if nothing else already claimed the alias.
        if (!$container->hasDefinition(NullAuditRecorder::class)) {
            $container->register(NullAuditRecorder::class, NullAuditRecorder::class)
                ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setPublic(false);
        }
        if (!$container->hasAlias(AuditRecorderInterface::class)
            && !$container->hasDefinition(AuditRecorderInterface::class)) {
            $container->setAlias(AuditRecorderInterface::class, NullAuditRecorder::class);
        }

        // App-facing facade.
        $container->register(AuditTrail::class, AuditTrail::class)
            ->setArgument('$recorder', new Reference(AuditRecorderInterface::class))
            ->setArgument('$registry', new Reference(AuditActionRegistry::class))
            ->setArgument('$strict', $strict)
            ->setPublic(true);

        $container->setAlias(AuditTrailInterface::class, AuditTrail::class)->setPublic(true);
    }
}
