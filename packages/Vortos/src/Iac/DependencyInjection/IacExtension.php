<?php

declare(strict_types=1);

namespace Vortos\Iac\DependencyInjection;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Iac\Attribute\InfraConfig;
use Vortos\Iac\Command\IacApplyCommand;
use Vortos\Iac\Command\IacDestroyCommand;
use Vortos\Iac\Command\IacDriftCommand;
use Vortos\Iac\Command\IacExportCommand;
use Vortos\Iac\Command\IacPlanCommand;
use Vortos\Iac\DependencyInjection\Compiler\CollectIacEnginesPass;
use Vortos\Iac\DependencyInjection\Compiler\CollectIacPoliciesPass;
use Vortos\Iac\Driver\Terraform\BinaryResolver;
use Vortos\Iac\Driver\Terraform\PlanJsonParser;
use Vortos\Iac\Driver\Terraform\ProcessRunnerInterface;
use Vortos\Iac\Driver\Terraform\SystemProcessRunner;
use Vortos\Iac\Driver\Terraform\TerraformEngine;
use Vortos\Iac\Export\ExportRunner;
use Vortos\Iac\Export\SafeFileWriter;
use Vortos\Iac\Exporter\Cache\CacheExporter;
use Vortos\Iac\Exporter\Compute\ComputeExporter;
use Vortos\Iac\Exporter\ComputeService\ComputeServiceExporter;
use Vortos\Iac\Exporter\Database\DatabaseExporter;
use Vortos\Iac\Exporter\Dns\DnsExporter;
use Vortos\Iac\Exporter\Iam\IamExporter;
use Vortos\Iac\Exporter\Kafka\KafkaTopicsExporter;
use Vortos\Iac\Exporter\Kafka\Mapper\ConfluentTopicMapper;
use Vortos\Iac\Exporter\Kafka\Mapper\MongeyKafkaTopicMapper;
use Vortos\Iac\Exporter\Network\NetworkExporter;
use Vortos\Iac\Exporter\ObjectStore\ObjectStoreBucketExporter;
use Vortos\Iac\Exporter\Queue\QueueExporter;
use Vortos\Iac\Lifecycle\Audit\IacAuditSinkInterface;
use Vortos\Iac\Lifecycle\Audit\NullIacAuditSink;
use Vortos\Iac\Lifecycle\IacDriftAuditor;
use Vortos\Iac\Lifecycle\IacDriftAuditorInterface;
use Vortos\Iac\Lifecycle\IacEngineInterface;
use Vortos\Iac\Lifecycle\IacEngineRegistry;
use Vortos\Iac\Lifecycle\IacLifecycleService;
use Vortos\Iac\Lifecycle\Policy\NullPlanPolicy;
use Vortos\Iac\Lifecycle\Policy\PlanPolicyInterface;
use Vortos\Iac\Lifecycle\Policy\PlanPolicyRegistry;
use Vortos\Iac\Lifecycle\StateBackend\StateBackendExporter;

final class IacExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_iac';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // ── Existing codegen seam ──

        $container->registerAttributeForAutoconfiguration(
            InfraConfig::class,
            static function (ChildDefinition $definition, InfraConfig $attribute): void {
                $definition->addTag('vortos.infra_config');
            }
        );

        if (!$container->hasParameter('vortos.iac.exports')) {
            $container->setParameter('vortos.iac.exports', []);
        }

        foreach ([ConfluentTopicMapper::class, MongeyKafkaTopicMapper::class] as $mapper) {
            $container->register($mapper, $mapper)->setPublic(false);
        }

        $container->register(KafkaTopicsExporter::class, KafkaTopicsExporter::class)
            ->setArgument('$mappers', [
                new Reference(ConfluentTopicMapper::class),
                new Reference(MongeyKafkaTopicMapper::class),
            ])
            ->setPublic(false);

        $container->register(ObjectStoreBucketExporter::class, ObjectStoreBucketExporter::class)
            ->setPublic(false);

        // Block 23: new exporter families
        foreach ([
            ComputeExporter::class,
            ComputeServiceExporter::class,
            NetworkExporter::class,
            DatabaseExporter::class,
            CacheExporter::class,
            DnsExporter::class,
            IamExporter::class,
            QueueExporter::class,
            StateBackendExporter::class,
        ] as $exporterClass) {
            $container->register($exporterClass, $exporterClass)->setPublic(false);
        }

        $container->register(SafeFileWriter::class, SafeFileWriter::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setPublic(false);

        $exporterMap = [
            KafkaTopicsExporter::class => new Reference(KafkaTopicsExporter::class),
            ObjectStoreBucketExporter::class => new Reference(ObjectStoreBucketExporter::class),
            ComputeExporter::class => new Reference(ComputeExporter::class),
            ComputeServiceExporter::class => new Reference(ComputeServiceExporter::class),
            NetworkExporter::class => new Reference(NetworkExporter::class),
            DatabaseExporter::class => new Reference(DatabaseExporter::class),
            CacheExporter::class => new Reference(CacheExporter::class),
            DnsExporter::class => new Reference(DnsExporter::class),
            IamExporter::class => new Reference(IamExporter::class),
            QueueExporter::class => new Reference(QueueExporter::class),
            StateBackendExporter::class => new Reference(StateBackendExporter::class),
        ];

        $container->register(ExportRunner::class, ExportRunner::class)
            ->setArgument('$exporters', $exporterMap)
            ->setArgument('$exports', '%vortos.iac.exports%')
            ->setArgument('$writer', new Reference(SafeFileWriter::class))
            ->setPublic(false);

        $container->register(IacExportCommand::class, IacExportCommand::class)
            ->setArgument('$runner', new Reference(ExportRunner::class))
            ->setPublic(true)
            ->addTag('console.command');

        // ── Block 23: Lifecycle seam ──

        // Engine registry
        $container->register(CollectIacEnginesPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(IacEngineRegistry::class, IacEngineRegistry::class)
            ->setArgument('$drivers', new Reference(CollectIacEnginesPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(IacEngineInterface::class)
            ->addTag(CollectIacEnginesPass::TAG);

        // Policy registry
        $container->register(CollectIacPoliciesPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(PlanPolicyRegistry::class, PlanPolicyRegistry::class)
            ->setArgument('$drivers', new Reference(CollectIacPoliciesPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(PlanPolicyInterface::class)
            ->addTag(CollectIacPoliciesPass::TAG);

        // Default null policy
        $container->register(NullPlanPolicy::class, NullPlanPolicy::class)
            ->addTag(CollectIacPoliciesPass::TAG)
            ->setPublic(false);

        // Audit sink (null default)
        $container->register(NullIacAuditSink::class, NullIacAuditSink::class)
            ->setPublic(false);

        $container->setAlias(IacAuditSinkInterface::class, NullIacAuditSink::class);

        // Optional Block 16 ledger integration
        if (class_exists(\Vortos\Observability\Audit\AuditHashChain::class)) {
            $container->register(\Vortos\Iac\Lifecycle\Audit\LedgerIacAuditSink::class, \Vortos\Iac\Lifecycle\Audit\LedgerIacAuditSink::class)
                ->setArgument('$chain', new Reference(\Vortos\Observability\Audit\AuditHashChain::class))
                ->setArgument('$hmacKey', '%vortos.iac.audit_hmac_key%')
                ->setPublic(false);
        }

        if (!$container->hasParameter('vortos.iac.audit_hmac_key')) {
            $container->setParameter('vortos.iac.audit_hmac_key', '');
        }

        // Process runner
        $container->register(SystemProcessRunner::class, SystemProcessRunner::class)
            ->setPublic(false);

        $container->setAlias(ProcessRunnerInterface::class, SystemProcessRunner::class);

        // Binary resolver
        $container->register(BinaryResolver::class, BinaryResolver::class)
            ->setArgument('$runner', new Reference(ProcessRunnerInterface::class))
            ->setPublic(false);

        // Plan JSON parser
        $container->register(PlanJsonParser::class, PlanJsonParser::class)
            ->setPublic(false);

        // Terraform engine driver
        $container->register(TerraformEngine::class, TerraformEngine::class)
            ->setArgument('$runner', new Reference(ProcessRunnerInterface::class))
            ->setArgument('$resolver', new Reference(BinaryResolver::class))
            ->setArgument('$parser', new Reference(PlanJsonParser::class))
            ->addTag(CollectIacEnginesPass::TAG)
            ->setPublic(false);

        // Lifecycle service
        $container->register(IacLifecycleService::class, IacLifecycleService::class)
            ->setArgument('$engine', new Reference(TerraformEngine::class))
            ->setArgument('$policy', new Reference(NullPlanPolicy::class))
            ->setArgument('$auditSink', new Reference(IacAuditSinkInterface::class))
            ->setPublic(false);

        // Drift auditor
        $container->register(IacDriftAuditor::class, IacDriftAuditor::class)
            ->setArgument('$lifecycle', new Reference(IacLifecycleService::class))
            ->setArgument('$workingDir', '%kernel.project_dir%/infra')
            ->setPublic(false);

        $container->setAlias(IacDriftAuditorInterface::class, IacDriftAuditor::class);

        // Lifecycle commands
        $container->register(IacPlanCommand::class, IacPlanCommand::class)
            ->setArgument('$lifecycle', new Reference(IacLifecycleService::class))
            ->setArgument('$workingDir', '%kernel.project_dir%/infra')
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(IacApplyCommand::class, IacApplyCommand::class)
            ->setArgument('$lifecycle', new Reference(IacLifecycleService::class))
            ->setArgument('$workingDir', '%kernel.project_dir%/infra')
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(IacDestroyCommand::class, IacDestroyCommand::class)
            ->setArgument('$lifecycle', new Reference(IacLifecycleService::class))
            ->setArgument('$workingDir', '%kernel.project_dir%/infra')
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(IacDriftCommand::class, IacDriftCommand::class)
            ->setArgument('$auditor', new Reference(IacDriftAuditorInterface::class))
            ->addTag('console.command')
            ->setPublic(false);

        // ── Block 23: Deploy doctor integration (class_exists-guarded) ──

        if (class_exists(\Vortos\Iac\Lifecycle\IacDriftAuditorInterface::class)
            && class_exists(\Vortos\Deploy\Preflight\PreflightCheckInterface::class)) {
            $container->register(\Vortos\Deploy\Preflight\Check\IacDriftCheck::class, \Vortos\Deploy\Preflight\Check\IacDriftCheck::class)
                ->setArgument('$auditor', new Reference(IacDriftAuditorInterface::class))
                ->addTag('vortos.deploy.preflight_check')
                ->setPublic(false);
        }
    }
}
