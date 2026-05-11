<?php

declare(strict_types=1);

namespace Vortos\Tests\Logger;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\EventListener\LogBufferFlushListener;
use Vortos\Logger\DependencyInjection\LoggerExtension;
use Vortos\Logger\Processor\CorrelationIdProcessor;
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
    }

    public function test_dev_uses_stream_handler_by_default(): void
    {
        // rotation=false via config
        $this->writeLoggingConfig($this->tmpDir, 'rotation(false)');
        $container = $this->makeContainer('dev');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.handler.file'));
        $def = $container->getDefinition('vortos.logger.handler.file');
        $this->assertSame(StreamHandler::class, $def->getClass());
    }

    public function test_dev_uses_rotating_handler_when_enabled(): void
    {
        $container = $this->makeContainer('dev');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.handler.file'));
        $def = $container->getDefinition('vortos.logger.handler.file');
        $this->assertSame(RotatingFileHandler::class, $def->getClass());
    }

    public function test_buffer_handler_wraps_base_in_dev(): void
    {
        $container = $this->makeContainer('dev');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.handler.file.buffered'));
        $def = $container->getDefinition('vortos.logger.handler.file.buffered');
        $this->assertSame(BufferHandler::class, $def->getClass());
        $this->assertSame(LogBufferFlushListener::class, $container->getDefinition('vortos.logger.handler.flush_listener')->getClass());
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

    public function test_sentry_configuration_fails_fast_when_package_missing(): void
    {
        if (class_exists(\Sentry\Monolog\Handler::class)) {
            $this->markTestSkipped('sentry/sentry is installed.');
        }

        $this->writeLoggingConfig($this->tmpDir, 'sentry(\'https://key@sentry.io/123\')');
        $container = $this->makeContainer('prod');

        $this->expectException(\RuntimeException::class);
        (new LoggerExtension())->load([], $container);
    }

    public function test_disabled_channel_gets_null_handler(): void
    {
        $this->writeLoggingConfig($this->tmpDir, 'disableChannel(LogChannel::Cache)');
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $nullHandlerId = 'vortos.logger.cache.null_handler';
        $this->assertTrue($container->hasDefinition($nullHandlerId));
        $def = $container->getDefinition($nullHandlerId);
        $this->assertSame(NullHandler::class, $def->getClass());
    }

    public function test_correlation_id_processor_registered_when_enabled(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.processor.correlation_id'));
        $def = $container->getDefinition('vortos.logger.processor.correlation_id');
        $this->assertSame(CorrelationIdProcessor::class, $def->getClass());
    }

    public function test_correlation_id_processor_not_registered_when_disabled(): void
    {
        $this->writeLoggingConfig($this->tmpDir, 'correlationId(false)');
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertFalse($container->hasDefinition('vortos.logger.processor.correlation_id'));
    }

    public function test_prod_uses_stderr_handler(): void
    {
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition('vortos.logger.handler.stderr'));
        $def = $container->getDefinition('vortos.logger.handler.stderr');
        $this->assertSame(StreamHandler::class, $def->getClass());
        $this->assertSame('php://stderr', $def->getArgument(0));
    }

    public function test_loads_project_config_file(): void
    {
        $this->writeLoggingConfig($this->tmpDir, 'disableChannel(LogChannel::Query)');
        $container = $this->makeContainer('prod');
        (new LoggerExtension())->load([], $container);

        // Query channel should have NullHandler when disabled
        $this->assertTrue($container->hasDefinition('vortos.logger.query.null_handler'));
    }

    private function makeContainer(string $env): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.env', $env);
        $container->setParameter('kernel.project_dir', $this->tmpDir);
        $container->setParameter('kernel.log_path', $this->tmpDir . '/var/log');
        return $container;
    }

    private function writeLoggingConfig(string $dir, string $configCall): void
    {
        $content = <<<PHP
<?php
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;
return static function (VortosLoggingConfig \$config): void {
    \$config->{$configCall};
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
