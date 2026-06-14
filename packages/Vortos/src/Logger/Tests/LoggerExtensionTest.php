<?php

declare(strict_types=1);

namespace Vortos\Logger\Tests;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SamplingHandler;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\LoggerExtension;
use Vortos\Logger\Flush\FlushBootListener;
use Vortos\Logger\Flush\FlushScheduler;
use Vortos\Logger\Handler\CompressingRotatingFileHandler;
use Vortos\Logger\HashChain\InMemoryHashChainState;
use Vortos\Logger\Processor\CorrelationIdProcessor;
use Vortos\Logger\Processor\HashChainProcessor;
use Vortos\Logger\Processor\RedactionProcessor;
use Vortos\Logger\Processor\RequestContextProcessor;
use Vortos\Logger\Processor\StructuredLogProcessor;

final class LoggerExtensionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vortos_logger_test_' . uniqid();
        mkdir($this->tmpDir . '/config', 0777, true);
        mkdir($this->tmpDir . '/var/log', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_registers_all_channels(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        foreach (LogChannel::cases() as $channel) {
            $this->assertTrue(
                $container->hasDefinition('vortos.logger.' . $channel->value),
                "Channel {$channel->value} not registered",
            );
        }
    }

    public function test_logger_interface_aliased_to_app_channel(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertSame('vortos.logger.app', (string) $container->getAlias(LoggerInterface::class));
        $this->assertSame('vortos.logger.app', (string) $container->getAlias('monolog.logger'));
    }

    public function test_prod_sink_uses_stream_handler_to_stderr(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $def = $container->getDefinition('vortos.logger.sink.app.handler');
        $this->assertSame(StreamHandler::class, $def->getClass());
        $this->assertSame('php://stderr', $def->getArgument(0));
    }

    public function test_dev_sink_uses_compressing_rotating_file_handler(): void
    {
        $container = $this->makeContainer('dev');
        (new LoggerExtension())->load([], $container);

        $def = $container->getDefinition('vortos.logger.sink.app.handler');
        $this->assertSame(CompressingRotatingFileHandler::class, $def->getClass());
        $this->assertSame($this->tmpDir . '/var/log/app.log', $def->getArgument(0));
    }

    public function test_dev_sink_uses_plain_rotating_handler_when_compression_disabled(): void
    {
        $this->writeLoggingConfig($this->tmpDir, "sink(LogChannel::App->value)->toFile('app.log')->rotation(compress: false)");
        $container = $this->makeContainer('dev');
        (new LoggerExtension())->load([], $container);

        $def = $container->getDefinition('vortos.logger.sink.app.handler');
        $this->assertSame(\Monolog\Handler\RotatingFileHandler::class, $def->getClass());
    }

    public function test_batched_sink_wraps_handler_in_buffer_and_registers_flush_scheduler(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.sink.app.buffered'));
        $def = $container->getDefinition('vortos.logger.sink.app.buffered');
        $this->assertSame(BufferHandler::class, $def->getClass());

        $schedulerDef = $container->getDefinition(FlushScheduler::class);
        $registerCalls = array_filter($schedulerDef->getMethodCalls(), static fn(array $call) => $call[0] === 'register');
        $this->assertNotEmpty($registerCalls);
    }

    public function test_security_and_audit_sinks_are_not_buffered(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertFalse($container->hasDefinition('vortos.logger.sink.security.buffered'));
        $this->assertFalse($container->hasDefinition('vortos.logger.sink.audit.buffered'));
    }

    public function test_flush_scheduler_is_started(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $schedulerDef = $container->getDefinition(FlushScheduler::class);
        $startCalls = array_filter($schedulerDef->getMethodCalls(), static fn(array $call) => $call[0] === 'start');
        $this->assertNotEmpty($startCalls);
    }

    public function test_flush_boot_listener_registered_as_event_subscriber(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $def = $container->getDefinition(FlushBootListener::class);
        $this->assertTrue($def->hasTag('kernel.event_subscriber'));
    }

    public function test_sampling_wraps_sink_handler(): void
    {
        $this->writeLoggingConfig($this->tmpDir, "sink(LogChannel::Cache->value)->sample(50)");
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.sink.cache.sampled'));
        $def = $container->getDefinition('vortos.logger.sink.cache.sampled');
        $this->assertSame(SamplingHandler::class, $def->getClass());
        $this->assertSame(50, $def->getArgument(1));
    }

    public function test_custom_handler_sink_references_user_service(): void
    {
        $this->writeLoggingConfig(
            $this->tmpDir,
            "sink('siem')->customHandler('app.logging.siem_handler')",
            "channel(LogChannel::Security)->alsoRouteTo('siem')",
        );
        $container = $this->makeContainer('prod');
        $container->register('app.logging.siem_handler', NullHandler::class)->setPublic(false);

        (new LoggerExtension())->load([], $container);

        $filterDef = $container->getDefinition('vortos.logger.security.filter.siem');
        $bufferedId = (string) $filterDef->getArgument(0);
        $this->assertSame('vortos.logger.sink.siem.buffered', $bufferedId);

        $bufferedDef = $container->getDefinition($bufferedId);
        $this->assertSame('app.logging.siem_handler', (string) $bufferedDef->getArgument(0));
    }

    public function test_prod_does_not_register_introspection_by_default(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertFalse($container->hasDefinition('vortos.logger.processor.introspection'));
    }

    public function test_dev_registers_introspection_by_default(): void
    {
        $container = $this->makeContainer('dev');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.processor.introspection'));
    }

    public function test_enterprise_processors_are_registered_by_default(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertSame(RedactionProcessor::class, $container->getDefinition('vortos.logger.processor.redaction')->getClass());
        $this->assertSame(StructuredLogProcessor::class, $container->getDefinition('vortos.logger.processor.structured')->getClass());
        $this->assertSame(RequestContextProcessor::class, $container->getDefinition('vortos.logger.processor.request_context')->getClass());
    }

    public function test_hash_chain_processor_registered_for_audit_channel(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertSame(InMemoryHashChainState::class, $container->getDefinition('vortos.logger.hash_chain_state')->getClass());
        $this->assertSame(HashChainProcessor::class, $container->getDefinition('vortos.logger.processor.hash_chain')->getClass());

        $auditDef = $container->getDefinition('vortos.logger.audit');
        $processorCalls = array_filter($auditDef->getMethodCalls(), static fn(array $call) => $call[0] === 'pushProcessor');
        $referencedIds = array_map(static fn(array $call) => (string) $call[1][0], $processorCalls);
        $this->assertContains('vortos.logger.processor.hash_chain', $referencedIds);
    }

    public function test_sentry_configuration_fails_fast_when_package_missing(): void
    {
        if (class_exists(\Sentry\Monolog\Handler::class)) {
            $this->markTestSkipped('sentry/sentry is installed.');
        }

        $this->writeLoggingConfig($this->tmpDir, "sentry('https://key@sentry.io/123')");
        $container = $this->makeContainer('prod');

        $this->expectException(\RuntimeException::class);
        (new LoggerExtension())->load([], $container);
    }

    public function test_disabled_channel_gets_null_handler(): void
    {
        $this->writeLoggingConfig($this->tmpDir, "channel(LogChannel::Cache)->disable()");
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $nullHandlerId = 'vortos.logger.cache.null_handler';
        $this->assertTrue($container->hasDefinition($nullHandlerId));
        $this->assertSame(NullHandler::class, $container->getDefinition($nullHandlerId)->getClass());
    }

    public function test_channel_filter_handler_uses_configured_level(): void
    {
        $this->writeLoggingConfig($this->tmpDir, "channel(LogChannel::Http)->level(Level::Info)");
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $def = $container->getDefinition('vortos.logger.http.filter.http');
        $this->assertSame(FilterHandler::class, $def->getClass());
        $this->assertSame(\Monolog\Level::Info, $def->getArgument(1));
    }

    public function test_correlation_id_processor_registered_when_enabled(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.processor.correlation_id'));
        $this->assertSame(CorrelationIdProcessor::class, $container->getDefinition('vortos.logger.processor.correlation_id')->getClass());
    }

    public function test_correlation_id_processor_not_registered_when_disabled(): void
    {
        $this->writeLoggingConfig($this->tmpDir, "correlationId(false)");
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertFalse($container->hasDefinition('vortos.logger.processor.correlation_id'));
    }

    public function test_config_stub_registered(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.config_stub.logging'));
    }

    private function makeContainer(string $env): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.env', $env);
        $container->setParameter('kernel.project_dir', $this->tmpDir);
        $container->setParameter('kernel.log_path', $this->tmpDir . '/var/log');
        return $container;
    }

    private function writeLoggingConfig(string $dir, string ...$configCalls): void
    {
        $calls = implode("\n    ", array_map(static fn(string $call) => '$config->' . $call . ';', $configCalls));
        $content = <<<PHP
<?php
use Monolog\Level;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;
return static function (VortosLoggingConfig \$config): void {
    {$calls}
};
PHP;
        file_put_contents($dir . '/config/logging.php', $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
