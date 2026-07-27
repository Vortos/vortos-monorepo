<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Observability\Audit\AuditChainVerifier;
use Vortos\Observability\Audit\AuditExportService;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Observability\Audit\DbalDeployAuditViewRepository;
use Vortos\Observability\Audit\DeployAuditProjector;
use Vortos\Observability\Audit\DeployAuditViewRepositoryInterface;
use Vortos\Observability\Command\AuditExportCommand;
use Vortos\Observability\Command\AuditVerifyCommand;

/**
 * Registers the tamper-evident DEPLOY audit ledger, in a pass rather than in Extension::load().
 *
 * This block had both of the defects that cost the ALERT audit ledger its contents (FB-36/FB-37):
 *
 *  1. It opened with `if (!$container->has(Connection::class)) return;`. The Connection is
 *     registered by vortos-persistence's extension, so during load() that is a race against
 *     extension order — the class belonging to Doctrine rather than to a vortos package changes
 *     nothing about who registers the service. Losing it returned early and the entire ledger,
 *     including its verify and export commands, silently ceased to exist.
 *
 *  2. It read `$_ENV['OBSERVABILITY_AUDIT_HMAC_KEY']` inline and registered the projector only when
 *     that came back non-empty. The container compiles wherever the image is built, not where it
 *     runs, so on a host that HAS the key the projector could still be omitted — recording nothing
 *     while deploys proceeded normally and nothing reported the gap.
 *
 * A compiler pass answers the first question instead of guessing it. The key is a declared
 * `%env()%` reference, so whether it is usable is decided at runtime by the service that needs it
 * rather than by a service quietly not existing.
 */
final class DeployAuditWiringPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Deploy\Audit\DeployAuditSinkInterface::class)) {
            return; // vortos-deploy absent: audit entries are built from its domain events.
        }

        if (!$container->has(Connection::class)) {
            return; // genuinely no database to hold the ledger.
        }

        if ($container->hasDefinition(DbalDeployAuditViewRepository::class)) {
            return; // already registered.
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->setParameter('env(OBSERVABILITY_AUDIT_HMAC_KEY)', '');

        $container->register(DbalDeployAuditViewRepository::class, DbalDeployAuditViewRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'observability_deploy_audit_log')
            ->setPublic(false);

        $container->setAlias(DeployAuditViewRepositoryInterface::class, DbalDeployAuditViewRepository::class)
            ->setPublic(false);

        $container->register(AuditHashChain::class, AuditHashChain::class)->setPublic(false);

        $container->register(AuditChainVerifier::class, AuditChainVerifier::class)
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setPublic(false);

        // Registered unconditionally. Whether the key is usable is a RUNTIME question; a projector
        // that vanishes at compile time is indistinguishable from one that is working.
        $container->register(DeployAuditProjector::class, DeployAuditProjector::class)
            ->setArgument('$repository', new Reference(DeployAuditViewRepositoryInterface::class))
            ->setArgument('$hmacKey', '%env(string:OBSERVABILITY_AUDIT_HMAC_KEY)%')
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setPublic(false);

        $container->register(AuditExportService::class, AuditExportService::class)
            ->setArgument('$repository', new Reference(DeployAuditViewRepositoryInterface::class))
            ->setArgument('$hmacKey', '%env(string:OBSERVABILITY_AUDIT_HMAC_KEY)%')
            ->setPublic(false);

        $container->register(AuditExportCommand::class, AuditExportCommand::class)
            ->setArgument('$exporter', new Reference(AuditExportService::class))
            ->setPublic(false);

        $container->register(AuditVerifyCommand::class, AuditVerifyCommand::class)
            ->setArgument('$repository', new Reference(DeployAuditViewRepositoryInterface::class))
            ->setArgument('$verifier', new Reference(AuditChainVerifier::class))
            ->setArgument('$hmacKey', '%env(string:OBSERVABILITY_AUDIT_HMAC_KEY)%')
            ->setPublic(false);
    }
}
