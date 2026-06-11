<?php

declare(strict_types=1);

namespace Vortos\Iac\DependencyInjection;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Iac\Attribute\InfraConfig;
use Vortos\Iac\Command\IacExportCommand;
use Vortos\Iac\Export\ExportRunner;
use Vortos\Iac\Export\SafeFileWriter;
use Vortos\Iac\Exporter\Kafka\KafkaTopicsExporter;
use Vortos\Iac\Exporter\Kafka\Mapper\ConfluentTopicMapper;
use Vortos\Iac\Exporter\Kafka\Mapper\MongeyKafkaTopicMapper;
use Vortos\Iac\Exporter\ObjectStore\ObjectStoreBucketExporter;

final class IacExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_iac';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
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

        $container->register(SafeFileWriter::class, SafeFileWriter::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setPublic(false);

        $container->register(ExportRunner::class, ExportRunner::class)
            ->setArgument('$exporters', [
                KafkaTopicsExporter::class => new Reference(KafkaTopicsExporter::class),
                ObjectStoreBucketExporter::class => new Reference(ObjectStoreBucketExporter::class),
            ])
            ->setArgument('$exports', '%vortos.iac.exports%')
            ->setArgument('$writer', new Reference(SafeFileWriter::class))
            ->setPublic(false);

        $container->register(IacExportCommand::class, IacExportCommand::class)
            ->setArgument('$runner', new Reference(ExportRunner::class))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
