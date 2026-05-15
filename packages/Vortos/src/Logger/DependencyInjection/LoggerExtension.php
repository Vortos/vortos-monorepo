<?php

declare(strict_types=1);

namespace Vortos\Logger\DependencyInjection;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\EventListener\LogBufferFlushListener;
use Vortos\Logger\Processor\CorrelationIdProcessor;
use Vortos\Logger\Processor\RedactionProcessor;
use Vortos\Logger\Processor\RequestContextProcessor;
use Vortos\Logger\Processor\StructuredLogProcessor;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wires Monolog as the PSR-3 logger with named channels, rotation, buffering,
 * trace correlation, and optional alerting handlers.
 *
 * ## Named channels
 *
 *   LoggerInterface          → vortos.logger.app   (userland default)
 *   vortos.logger.http       → Http channel
 *   vortos.logger.cqrs       → Cqrs channel
 *   vortos.logger.messaging  → Messaging channel
 *   vortos.logger.cache      → Cache channel
 *   vortos.logger.security   → Security channel
 *   vortos.logger.query      → Query channel
 *
 * ## Dev vs Prod
 *
 *   Dev:  RotatingFileHandler → var/log/app-{date}.log at DEBUG
 *         IntrospectionProcessor adds file:line:class to every record
 *   Prod: StreamHandler → php://stderr at ERROR, JSON format
 *
 * ## BufferHandler
 *
 *   Wraps the real handler by default. Collects records in memory and flushes
 *   once at PHP shutdown — reduces disk I/O from N writes per request to 1.
 *
 * ## Alerting handlers
 *
 *   Sentry, Slack, and email handlers are appended per channel when configured
 *   in VortosLoggingConfig. Each triggers at or above its configured minimum level.
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

        $resolved = $config->toArray();

        $this->registerFormatters($container);
        $this->registerProcessors($container, $resolved);
        $baseHandlerId = $this->registerBaseHandler($container, $env, $logPath, $resolved);
        $this->registerChannels($container, $env, $baseHandlerId, $resolved);
    }

    private function registerFormatters(ContainerBuilder $container): void
    {
        $container->register('vortos.logger.formatter.json', JsonFormatter::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register('vortos.logger.formatter.line', LineFormatter::class)
            ->setArguments([null, null, true, true])
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerProcessors(ContainerBuilder $container, array $resolved): void
    {
        if ($resolved['introspection']) {
            $container->register('vortos.logger.processor.introspection', IntrospectionProcessor::class)
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved['redaction']) {
            $container->register('vortos.logger.processor.redaction', RedactionProcessor::class)
                ->setArguments([$resolved['redaction_keys']])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved['structured']) {
            $container->register('vortos.logger.processor.structured', StructuredLogProcessor::class)
                ->setArguments([
                    $resolved['service_name'],
                    $resolved['service_version'],
                    $resolved['deployment_environment'],
                ])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved['request_context']) {
            $container->register('vortos.logger.processor.request_context', RequestContextProcessor::class)
                ->setArguments([
                    new Reference(RequestStack::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(CurrentUserProvider::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved['correlation_id']) {
            $container->register('vortos.logger.processor.correlation_id', CorrelationIdProcessor::class)
                ->setArgument('$tracer', new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setShared(true)
                ->setPublic(false);
        }
    }

    private function registerBaseHandler(ContainerBuilder $container, string $env, string $logPath, array $resolved): string
    {
        if ($env === 'dev') {
            $defaultLevel = $resolved['channel_levels'][LogChannel::App->value] ?? Level::Debug;
            $handlerId    = 'vortos.logger.handler.file';

            if ($resolved['rotation_enabled']) {
                $container->register($handlerId, RotatingFileHandler::class)
                    ->setArguments([$logPath . '/app.log', $resolved['max_files'], $defaultLevel])
                    ->addMethodCall('setFormatter', [new Reference('vortos.logger.formatter.line')])
                    ->setShared(true)
                    ->setPublic(false);
            } else {
                $container->register($handlerId, StreamHandler::class)
                    ->setArguments([$logPath . '/app.log', $defaultLevel])
                    ->addMethodCall('setFormatter', [new Reference('vortos.logger.formatter.line')])
                    ->setShared(true)
                    ->setPublic(false);
            }
        } else {
            $defaultLevel = $resolved['channel_levels'][LogChannel::App->value] ?? Level::Error;
            $handlerId    = 'vortos.logger.handler.stderr';

            $container->register($handlerId, StreamHandler::class)
                ->setArguments(['php://stderr', $defaultLevel])
                ->addMethodCall('setFormatter', [new Reference('vortos.logger.formatter.json')])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($resolved['buffer_enabled']) {
            $bufferedId = $handlerId . '.buffered';
            $container->register($bufferedId, BufferHandler::class)
                ->setArguments([new Reference($handlerId), 500, Level::Debug, true])
                ->setShared(true)
                ->setPublic(false);
            $container->register('vortos.logger.handler.flush_listener', LogBufferFlushListener::class)
                ->setArgument('$handler', new Reference($bufferedId))
                ->addTag('kernel.event_subscriber')
                ->setShared(true)
                ->setPublic(false);
            return $bufferedId;
        }

        return $handlerId;
    }

    private function registerChannels(ContainerBuilder $container, string $env, string $baseHandlerId, array $resolved): void
    {
        $disabled  = $resolved['disabled_channels'];
        $isDevMode = $env === 'dev';

        foreach (LogChannel::cases() as $channel) {
            $serviceId = 'vortos.logger.' . $channel->value;
            $isDisabled = in_array($channel->value, $disabled, true);

            if ($isDisabled) {
                // Register a no-output NullHandler channel so injections still resolve
                $nullHandlerId = $serviceId . '.null_handler';
                $container->register($nullHandlerId, \Monolog\Handler\NullHandler::class)
                    ->setShared(true)
                    ->setPublic(false);

                $container->register($serviceId, Logger::class)
                    ->setArguments([$channel->value])
                    ->addMethodCall('pushHandler', [new Reference($nullHandlerId)])
                    ->setShared(true)
                    ->setPublic($channel === LogChannel::App);

                continue;
            }

            $channelLevel = $resolved['channel_levels'][$channel->value]
                ?? ($isDevMode ? Level::Debug : ($channel === LogChannel::App ? Level::Warning : Level::Error));

            $def = $container->register($serviceId, Logger::class)
                ->setArguments([$channel->value])
                ->setShared(true)
                ->setPublic($channel === LogChannel::App);

            // Base handler — shared across channels, but each channel filters by its own level
            // We create a per-channel FilterHandler wrapper so channel levels work independently
            $filterHandlerId = $serviceId . '.filter_handler';
            $container->register($filterHandlerId, \Monolog\Handler\FilterHandler::class)
                ->setArguments([new Reference($baseHandlerId), $channelLevel, Level::Emergency, true])
                ->setShared(true)
                ->setPublic(false);

            $def->addMethodCall('pushHandler', [new Reference($filterHandlerId)]);

            // Monolog unshifts processors; add redaction first so it runs last
            // after all enrichment processors have added context.
            if ($container->hasDefinition('vortos.logger.processor.redaction')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.redaction')]);
            }

            // Alerting handlers — added at channel level, fire independently of base handler
            foreach ($resolved['sentry_handlers'] as $i => $sentry) {
                $handlerId = $serviceId . '.sentry_' . $i;
                if ($this->registerSentryHandler($container, $handlerId, $sentry['dsn'], $sentry['minLevel'], $resolved['fail_on_missing_integrations'])) {
                    $def->addMethodCall('pushHandler', [new Reference($handlerId)]);
                }
            }

            foreach ($resolved['slack_handlers'] as $i => $slack) {
                $innerHandlerId  = $serviceId . '.slack_' . $i . '.inner';
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

            foreach ($resolved['email_handlers'] as $i => $email) {
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

            if ($container->hasDefinition('vortos.logger.processor.introspection')) {
                $def->addMethodCall('pushProcessor', [new Reference('vortos.logger.processor.introspection')]);
            }

            if ($resolved['correlation_id'] && $container->hasDefinition('vortos.logger.processor.correlation_id')) {
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
