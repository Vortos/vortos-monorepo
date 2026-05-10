<?php

declare(strict_types=1);

namespace Vortos\Config\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Vortos\Config\Command\PublishConfigCommand;
use Vortos\Config\Service\ConfigFilePublisher;

final class ConfigExtension extends Extension
{
    public const STUB_TAG = 'vortos.config_stub';

    public function getAlias(): string
    {
        return 'vortos_config';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(ConfigFilePublisher::class, ConfigFilePublisher::class)
            ->setArgument('$stubs', new TaggedIteratorArgument(self::STUB_TAG))
            ->setPublic(false);

        $container->register(PublishConfigCommand::class, PublishConfigCommand::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->addTag('console.command');
    }
}
