<?php

declare(strict_types=1);

namespace Vortos\Logger\DependencyInjection;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SamplingHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Logger\Command\LogDiagnoseCommand;
use Vortos\Logger\Command\LogPruneCommand;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;
use Vortos\Logger\Config\BufferPolicy;
use Vortos\Logger\Config\ChannelDefinition;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\Config\ResolvedLoggingConfig;
use Vortos\Logger\Config\SinkDefinition;
use Vortos\Logger\Config\SinkDestination;
use Vortos\Logger\Flush\FlushBootListener;
use Vortos\Logger\Flush\FlushScheduler;
use Vortos\Logger\Handler\CompressingRotatingFileHandler;
use Vortos\Logger\HashChain\InMemoryHashChainState;
use Vortos\Logger\Processor\CorrelationIdProcessor;
use Vortos\Logger\Processor\HashChainProcessor;
use Vortos\Logger\Processor\RedactionProcessor;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Logger\Processor\RequestContextProcessor;
use Vortos\Logger\Processor\StructuredLogProcessor;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wires the Vortos logging pipeline: channels route to named sinks, each
 * sink is a self-contained handler stack (formatter, sampling, buffering,
 * rotation/compression, hash-chaining), and FlushScheduler guarantees
 * buffered sinks are flushed within a bounded time window regardless of
 * process lifecycle.
 *
 * Tag a service with `vortos.logger.handler` and reference it from
 * `SinkBuilder::customHandler()` to plug in OTLP/Kafka/SIEM transports.
 */
final class LoggerExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_logger';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $env        = $container->hasParameter('kernel.env') ? $container->getParameter('kernel.env') : 'prod';
        $projectDir = $container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : '';
        $logPath    = $container->hasParameter('kernel.log_path')
            ? $container->getParameter('kernel.log_path')
            : ($projectDir !== '' ? $projectDir . '/var/log' : throw new \RuntimeException('kernel.log_path is required when kernel.project_dir is not set.'));

        $config = new VortosLoggingConfig($env);
        $base   = $projectDir . '/config/logging.php';
        if ($projectDir !== '' && file_exists($base)) {
            (require $base)($config);
        }
        $envFile = $projectDir . '/config/' . $env . '/logging.php';
        if ($projectDir !== '' && file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $config->resolve();

        $this->registerFormatters($container);
        $this->registerProcessors($container, $resolved);
        $this->registerFlushScheduler($container);

        $sinkHandlerIds = $this->registerSinks($container, $resolved, $logPath);
        $this->registerChannels($container, $resolved, $sinkHandlerIds);
        $this->registerCommands($container, $resolved, $logPath);
    }

    private function registerFormatters(ContainerBuilder $container): void
    {
        $container->register('vortos.logger.formatter.json', JsonFormatter::class)
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerProcessors(ContainerBuilder $container, ResolvedLoggingConfig $resolved): void
    {
        if ($resolved->introspection) {
            $container->register('vortos.logger.processor.introspection', IntrospectionProcessor::class)
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved->redaction) {
            $container->register('vortos.logger.processor.redaction', RedactionProcessor::class)
                ->setArguments([$resolved->redactionKeys])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved->structured) {
            $container->register('vortos.logger.processor.structured', StructuredLogProcessor::class)
                ->setArguments([
                    $resolved->serviceName,
                    $resolved->serviceVersion,
                    $resolved->deploymentEnvironment,
                ])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved->requestContext) {
            $container->register('vortos.logger.processor.request_context', RequestContextProcessor::class)
                ->setArguments([
                    new Reference(RequestStack::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(CurrentUserProvider::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(IpResolverInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved->correlationId) {
            $container->register('vortos.logger.processor.correlation_id', CorrelationIdProcessor::class)
                ->setArgument('$tracer', new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setShared(true)
                ->setPublic(false);
        }

        $hashChainNeeded = false;
        foreach ($resolved->sinks as $sink) {
            if ($sink->hashChain) {
                $hashChainNeeded = true;
                break;
            }
        }

        if ($hashChainNeeded) {
            $container->register('vortos.logger.hash_chain_state', InMemoryHashChainState::class)
                ->setShared(true)
                ->setPublic(false);

            $container->register('vortos.logger.processor.hash_chain', HashChainProcessor::class)
                ->setArguments([new Reference('vortos.logger.hash_chain_state')])
                ->setShared(true)
                ->setPublic(false);
        }
    }

    private function registerFlushScheduler(ContainerBuilder $container): void
    {
        $container->register(FlushScheduler::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(FlushBootListener::class)
            ->setArguments([new Reference(FlushScheduler::class)])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)
            ->setPublic(false);
    }

    /**
     * @return array<string, string> sinkId => final (outermost) handler service id
     */
    private function registerSinks(ContainerBuilder $container, ResolvedLoggingConfig $resolved, string $logPath): array
    {
        $sinkHandlerIds = [];

        foreach ($resolved->sinks as $sinkId => $sink) {
            $handlerId = $this->registerSinkHandler($container, $sink, $logPath);

            if ($sink->sampleFactor !== null) {
                $sampledId = 'vortos.logger.sink.' . $sinkId . '.sampled';
                $container->register($sampledId, SamplingHandler::class)
                    ->setArguments([new Reference($handlerId), $sink->sampleFactor])
                    ->setShared(true)
                    ->setPublic(false);
                $handlerId = $sampledId;
            }

            if ($sink->bufferPolicy === BufferPolicy::Batched) {
                $bufferedId = 'vortos.logger.sink.' . $sinkId . '.buffered';
                $container->register($bufferedId, BufferHandler::class)
                    ->setArguments([new Reference($handlerId), 0, Level::Debug, true, true])
                    ->setShared(true)
                    ->setPublic(false);

                $container->getDefinition(FlushScheduler::class)
                    ->addMethodCall('register', [new Reference($bufferedId), $sink->flushIntervalSeconds]);

                $handlerId = $bufferedId;
            }

            $sinkHandlerIds[$sinkId] = $handlerId;
        }

        // Arm the periodic/shutdown flush triggers once all sinks are registered.
        if ($container->hasDefinition(FlushScheduler::class)) {
            $container->getDefinition(FlushScheduler::class)->addMethodCall('start', []);
        }

        return $sinkHandlerIds;
    }

    private function resolveFilePath(string $path, string $logPath): string
    {
        return str_starts_with($path, '/') ? $path : $logPath . '/' . $path;
    }

    private function registerCommands(ContainerBuilder $container, ResolvedLoggingConfig $resolved, string $logPath): void
    {
        $fileSinks = [];
        foreach ($resolved->sinks as $sinkId => $sink) {
            if ($sink->destination === SinkDestination::File && $sink->rotation->enabled) {
                $path = $this->resolveFilePath($sink->path ?? ($sinkId . '.log'), $logPath);

                $fileSinks[] = [
                    'sink' => $sinkId,
                    'dir' => dirname($path),
                    'filename' => basename($path),
                    'maxFiles' => $sink->rotation->maxFiles,
                    'maxAgeDays' => $sink->rotation->maxAgeDays,
                    'maxTotalSizeMb' => $sink->rotation->maxTotalSizeMb,
                    'compress' => $sink->rotation->compress,
                ];
            }
        }
        $container->setParameter('vortos.logger.file_sinks', $fileSinks);

        $topology = ['channels' => [], 'sinks' => []];

        foreach ($resolved->channels as $name => $channel) {
            $topology['channels'][$name] = [
                'sinkIds' => $channel->sinkIds,
                'level' => $channel->level->name,
                'disabled' => $channel->disabled,
            ];
        }

        foreach ($resolved->sinks as $sinkId => $sink) {
            $topology['sinks'][$sinkId] = [
                'destination' => $sink->destination->name,
                'path' => $sink->path,
                'level' => $sink->level->name,
                'bufferPolicy' => $sink->bufferPolicy->name,
                'sampleFactor' => $sink->sampleFactor,
                'hashChain' => $sink->hashChain,
                'flushIntervalSeconds' => $sink->flushIntervalSeconds,
                'rotation' => [
                    'enabled' => $sink->rotation->enabled,
                    'maxFiles' => $sink->rotation->maxFiles,
                    'maxAgeDays' => $sink->rotation->maxAgeDays,
                    'maxTotalSizeMb' => $sink->rotation->maxTotalSizeMb,
                    'compress' => $sink->rotation->compress,
                ],
            ];
        }
        $container->setParameter('vortos.logger.topology', $topology);

        $container->register(LogPruneCommand::class, LogPruneCommand::class)
            ->setArgument('$fileSinks', '%vortos.logger.file_sinks%')
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(LogDiagnoseCommand::class, LogDiagnoseCommand::class)
            ->setArgument('$topology', '%vortos.logger.topology%')
            ->setPublic(true)
            ->addTag('console.command');
    }

    private function registerSinkHandler(ContainerBuilder $container, SinkDefinition $sink, string $logPath): string
    {
        $handlerId = 'vortos.logger.sink.' . $sink->id . '.handler';

        switch ($sink->destination) {
            case SinkDestination::File:
                $path = $sink->path ?? throw \Vortos\Logger\Exception\InvalidLoggingConfigException::fileSinkMissingPath($sink->id);
                $path = $this->resolveFilePath($path, $logPath);

                if ($sink->rotation->enabled) {
                    $handlerClass = $sink->rotation->compress ? CompressingRotatingFileHandler::class : RotatingFileHandler::class;
                    $container->register($handlerId, $handlerClass)
                        ->setArguments([$path, $sink->rotation->maxFiles, $sink->level]);
                } else {
                    $container->register($handlerId, StreamHandler::class)
                        ->setArguments([$path, $sink->level]);
                }

                $container->getDefinition($handlerId)
                    ->addMethodCall('setFormatter', [new Reference('vortos.logger.formatter.json')])
                    ->setShared(true)
                    ->setPublic(false);
                break;

            case SinkDestination::Stream:
                $container->register($handlerId, StreamHandler::class)
                    ->setArguments([$sink->path, $sink->level])
                    ->addMethodCall('setFormatter', [new Reference('vortos.logger.formatter.json')])
                    ->setShared(true)
                    ->setPublic(false);
                break;

            case SinkDestination::Syslog:
                $container->register($handlerId, SyslogHandler::class)
                    ->setArguments([$sink->path, LOG_USER, $sink->level])
                    ->addMethodCall('setFormatter', [new Reference('vortos.logger.formatter.json')])
                    ->setShared(true)
                    ->setPublic(false);
                break;

            case SinkDestination::Custom:
                // Caller's handler is responsible for its own formatting.
                return (string) $sink->customHandlerServiceId;

            case SinkDestination::Null:
                $container->register($handlerId, NullHandler::class)
                    ->setShared(true)
                    ->setPublic(false);
                break;
        }

        return $handlerId;
    }

    private function registerChannels(ContainerBuilder $container, ResolvedLoggingConfig $resolved, array $sinkHandlerIds): void
    {
        foreach ($resolved->channels as $name => $channel) {
            $serviceId = 'vortos.logger.' . $name;
            $isApp = $name === LogChannel::App->value;

            if ($channel->disabled) {
                $nullHandlerId = $serviceId . '.null_handler';
                $container->register($nullHandlerId, NullHandler::class)
                    ->setShared(true)
                    ->setPublic(false);

                $container->register($serviceId, Logger::class)
                    ->setArguments([$name])
                    ->addMethodCall('pushHandler', [new Reference($nullHandlerId)])
                    ->setShared(true)
                    ->setPublic($isApp);

                continue;
            }

            $def = $container->register($serviceId, Logger::class)
                ->setArguments([$name])
                ->setShared(true)
                ->setPublic($isApp);

            foreach ($channel->sinkIds as $sinkId) {
                $filterHandlerId = $serviceId . '.filter.' . $sinkId;
                $container->register($filterHandlerId, FilterHandler::class)
                    ->setArguments([new Reference($sinkHandlerIds[$sinkId]), $channel->level, Level::Emergency, true])
                    ->setShared(true)
                    ->setPublic(false);

                $def->addMethodCall('pushHandler', [new Reference($filterHandlerId)]);
            }

            foreach ($resolved->sentryHandlers as $i => $sentry) {
                $handlerId = $serviceId . '.sentry_' . $i;
                if ($this->registerSentryHandler($container, $handlerId, $sentry['dsn'], $sentry['minLevel'], $resolved->failOnMissingIntegrations)) {
                    $def->addMethodCall('pushHandler', [new Reference($handlerId)]);
                }
            }

            foreach ($resolved->slackHandlers as $i => $slack) {
                $innerHandlerId   = $serviceId . '.slack_' . $i . '.inner';
                $bufferedHandlerId = $serviceId . '.slack_' . $i;
                $container->register($innerHandlerId, SlackWebhookHandler::class)
                    ->setArguments([$slack['webhook'], null, null, true, null, false, false, $slack['minLevel']])
                    ->setShared(true)
                    ->setPublic(false);
                $container->register($bufferedHandlerId, BufferHandler::class)
                    ->setArguments([new Reference($innerHandlerId), 0, $slack['minLevel'], true, true])
                    ->setShared(true)
                    ->setPublic(false);
                $def->addMethodCall('pushHandler', [new Reference($bufferedHandlerId)]);
            }

            foreach ($resolved->emailHandlers as $i => $email) {
                $innerHandlerId    = $serviceId . '.email_' . $i . '.inner';
                $bufferedHandlerId = $serviceId . '.email_' . $i;
                $container->register($innerHandlerId, NativeMailerHandler::class)
                    ->setArguments([$email['to'], 'Application Alert', 'noreply@localhost', $email['minLevel']])
                    ->setShared(true)
                    ->setPublic(false);
                $container->register($bufferedHandlerId, BufferHandler::class)
                    ->setArguments([new Reference($innerHandlerId), 0, $email['minLevel'], true, true])
                    ->setShared(true)
                    ->setPublic(false);
                $def->addMethodCall('pushHandler', [new Reference($bufferedHandlerId)]);
            }

            // Monolog unshifts processors — the last one pushed runs first.
            // Order pushed (hash_chain, redaction, introspection, correlation_id,
            // request_context, structured) yields execution order
            // (structured, request_context, correlation_id, introspection,
            // redaction, hash_chain) — hash chaining covers the final,
            // fully-enriched and redacted record.
            if ($name === LogChannel::Audit->value && $container->hasDefinition('vortos.logger.processor.hash_chain')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.hash_chain')]);
            }

            if ($container->hasDefinition('vortos.logger.processor.redaction')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.redaction')]);
            }

            if ($container->hasDefinition('vortos.logger.processor.introspection')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.introspection')]);
            }

            if ($container->hasDefinition('vortos.logger.processor.correlation_id')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.correlation_id')]);
            }

            if ($container->hasDefinition('vortos.logger.processor.request_context')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.request_context')]);
            }

            if ($container->hasDefinition('vortos.logger.processor.structured')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.structured')]);
            }
        }

        // Alias LoggerInterface to the App channel — userland default
        $container->setAlias(LoggerInterface::class, 'vortos.logger.app')
            ->setPublic(true);

        // Keep the legacy monolog.logger alias for any code that still uses it
        $container->setAlias('monolog.logger', 'vortos.logger.app')
            ->setPublic(true);

        $container->register('vortos.config_stub.logging', ConfigStub::class)
            ->setArguments(['logging', __DIR__ . '/../stubs/logging.php'])
            ->addTag(ConfigExtension::STUB_TAG)
            ->setPublic(false);
    }

    private function registerSentryHandler(ContainerBuilder $container, string $handlerId, string $dsn, Level $minLevel, bool $failOnMissing): bool
    {
        if (!class_exists(\Sentry\Monolog\Handler::class)) {
            if ($failOnMissing) {
                throw new \RuntimeException(
                    'vortos-logger: Sentry logging was configured but sentry/sentry is not installed. '
                    . 'Install sentry/sentry or call failOnMissingIntegrations(false).',
                );
            }

            return false;
        }

        $hubId = $handlerId . '_hub';
        $container->register($hubId, \Sentry\State\HubInterface::class)
            ->setFactory([\Sentry\SentrySdk::class, 'getCurrentHub'])
            ->setPublic(false);

        $container->register($handlerId, \Sentry\Monolog\Handler::class)
            ->setArguments([new Reference($hubId), $minLevel])
            ->setShared(true)
            ->setPublic(false);

        return true;
    }
}
