<?php
declare(strict_types=1);

namespace Vortos\Tracing\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Tracing\Config\TracingSampler;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\Decorator\ModuleAwareTracer;
use Vortos\Tracing\Decorator\SamplingTracer;
use Vortos\Tracing\NoOpTracer;
use Vortos\Tracing\Sampling\AlwaysOffSampler;
use Vortos\Tracing\Sampling\AlwaysOnSampler;
use Vortos\Tracing\Sampling\RatioSampler;
use Vortos\Tracing\Sampling\SamplerInterface;

final class TracingExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_tracing';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = new VortosTracingConfig();

        $appEnv = $container->getParameter('kernel.project_dir') 
            ? ($_ENV['APP_ENV'] ?? 'prod') 
            : 'prod';

        // Load user config if exists
        $configFile = $container->getParameter('kernel.project_dir') . '/config/tracing.php';
        if (file_exists($configFile)) {
            $userConfig = require $configFile;
            $userConfig($config);
        } else {
            // Sensible defaults per environment
            $config->sampler(
                $appEnv === 'dev' ? TracingSampler::AlwaysOn : TracingSampler::Ratio,
                rate: 0.1
            );
        }

        // Register inner tracer (NoOp by default — swap to OTel when needed)
        $container->register(NoOpTracer::class, NoOpTracer::class)
            ->setPublic(false);

        // Register sampler
        $sampler = match($config->getSampler()) {
            TracingSampler::AlwaysOn  => new Definition(AlwaysOnSampler::class),
            TracingSampler::AlwaysOff => new Definition(AlwaysOffSampler::class),
            TracingSampler::Ratio     => (new Definition(RatioSampler::class))
                ->setArguments([$config->getSamplerRate()]),
        };
        $container->setDefinition(SamplerInterface::class, $sampler)->setPublic(false);

        // Register SamplingTracer decorator
        $container->register(SamplingTracer::class, SamplingTracer::class)
            ->setArguments([
                new Reference(NoOpTracer::class),
                new Reference(SamplerInterface::class),
            ])
            ->setPublic(false);

        // Register ModuleAwareTracer decorator
        $container->register(ModuleAwareTracer::class, ModuleAwareTracer::class)
            ->setArguments([
                new Reference(SamplingTracer::class),
                $config->getDisabledModules(),
            ])
            ->setPublic(false);

        // Alias TracingInterface to the outermost decorator
        $container->setAlias(TracingInterface::class, ModuleAwareTracer::class)
            ->setPublic(true);
    }
}
