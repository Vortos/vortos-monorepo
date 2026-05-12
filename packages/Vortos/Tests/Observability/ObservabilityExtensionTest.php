<?php

declare(strict_types=1);

namespace Vortos\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Observability\Command\ListObservabilityStacksCommand;
use Vortos\Observability\Command\PublishObservabilityTemplatesCommand;
use Vortos\Observability\DependencyInjection\ObservabilityExtension;
use Vortos\Observability\Service\ObservabilityTemplatePublisher;
use Vortos\Observability\Service\ObservabilityTemplateRegistry;

final class ObservabilityExtensionTest extends TestCase
{
    public function test_registers_services_and_commands(): void
    {
        $container = new ContainerBuilder();

        (new ObservabilityExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition(ObservabilityTemplateRegistry::class));
        $this->assertTrue($container->hasDefinition(ObservabilityTemplatePublisher::class));
        $this->assertTrue($container->hasDefinition(ListObservabilityStacksCommand::class));
        $this->assertTrue($container->hasDefinition(PublishObservabilityTemplatesCommand::class));
    }
}

