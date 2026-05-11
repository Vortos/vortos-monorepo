<?php

declare(strict_types=1);

namespace Vortos\Tests\Logger;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;

final class VortosLoggingConfigTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        $this->previous = ['APP_ENV' => $_ENV['APP_ENV'] ?? null];
        $_ENV['APP_ENV'] = 'prod';
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function test_defaults_are_sensible(): void
    {
        $config = new VortosLoggingConfig();
        $arr    = $config->toArray();

        $this->assertEmpty($arr['disabled_channels']);
        $this->assertEmpty($arr['channel_levels']);
        $this->assertFalse($arr['rotation_enabled']); // prod env → false
        $this->assertSame(30, $arr['max_files']);
        $this->assertTrue($arr['buffer_enabled']);
        $this->assertTrue($arr['correlation_id']);
        $this->assertFalse($arr['introspection']);
        $this->assertTrue($arr['redaction']);
        $this->assertTrue($arr['structured']);
        $this->assertTrue($arr['request_context']);
        $this->assertTrue($arr['fail_on_missing_integrations']);
        $this->assertEmpty($arr['sentry_handlers']);
        $this->assertEmpty($arr['slack_handlers']);
        $this->assertEmpty($arr['email_handlers']);
    }

    public function test_rotation_enabled_in_dev(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $config = new VortosLoggingConfig();
        $this->assertTrue($config->toArray()['rotation_enabled']);
        $this->assertTrue($config->toArray()['introspection']);
    }

    public function test_can_set_channel_level(): void
    {
        $config = new VortosLoggingConfig();
        $config->channel(LogChannel::Messaging, Level::Warning);

        $this->assertSame(Level::Warning, $config->toArray()['channel_levels'][LogChannel::Messaging->value]);
    }

    public function test_can_disable_framework_channel(): void
    {
        $config = new VortosLoggingConfig();
        $config->disableChannel(LogChannel::Cache, LogChannel::Query);

        $disabled = $config->toArray()['disabled_channels'];
        $this->assertContains(LogChannel::Cache->value, $disabled);
        $this->assertContains(LogChannel::Query->value, $disabled);
    }

    public function test_app_channel_cannot_be_disabled(): void
    {
        $config = new VortosLoggingConfig();
        $config->disableChannel(LogChannel::App);

        $this->assertNotContains(LogChannel::App->value, $config->toArray()['disabled_channels']);
    }

    public function test_can_set_rotation(): void
    {
        $config = new VortosLoggingConfig();
        $config->rotation(true, 14);

        $arr = $config->toArray();
        $this->assertTrue($arr['rotation_enabled']);
        $this->assertSame(14, $arr['max_files']);
    }

    public function test_can_disable_buffer(): void
    {
        $config = new VortosLoggingConfig();
        $config->buffer(false);

        $this->assertFalse($config->toArray()['buffer_enabled']);
    }

    public function test_can_disable_correlation_id(): void
    {
        $config = new VortosLoggingConfig();
        $config->correlationId(false);

        $this->assertFalse($config->toArray()['correlation_id']);
    }

    public function test_can_configure_enterprise_processors(): void
    {
        $config = (new VortosLoggingConfig())
            ->introspection(true)
            ->redaction(true, ['secret'])
            ->structured(false)
            ->requestContext(false)
            ->service('checkout', '1.2.3', 'prod')
            ->failOnMissingIntegrations(false);

        $arr = $config->toArray();

        $this->assertTrue($arr['introspection']);
        $this->assertSame(['secret'], $arr['redaction_keys']);
        $this->assertFalse($arr['structured']);
        $this->assertFalse($arr['request_context']);
        $this->assertSame('checkout', $arr['service_name']);
        $this->assertSame('1.2.3', $arr['service_version']);
        $this->assertSame('prod', $arr['deployment_environment']);
        $this->assertFalse($arr['fail_on_missing_integrations']);
    }

    public function test_sentry_handler_registered_when_dsn_provided(): void
    {
        $config = new VortosLoggingConfig();
        $config->sentry('https://key@sentry.io/123', Level::Critical);

        $handlers = $config->toArray()['sentry_handlers'];
        $this->assertCount(1, $handlers);
        $this->assertSame('https://key@sentry.io/123', $handlers[0]['dsn']);
        $this->assertSame(Level::Critical, $handlers[0]['minLevel']);
    }

    public function test_sentry_handler_skipped_when_dsn_empty(): void
    {
        $config = new VortosLoggingConfig();
        $config->sentry('');

        $this->assertEmpty($config->toArray()['sentry_handlers']);
    }

    public function test_slack_handler_registered_when_webhook_provided(): void
    {
        $config = new VortosLoggingConfig();
        $config->slack('https://hooks.slack.com/xxx', Level::Critical);

        $handlers = $config->toArray()['slack_handlers'];
        $this->assertCount(1, $handlers);
        $this->assertSame('https://hooks.slack.com/xxx', $handlers[0]['webhook']);
    }

    public function test_slack_handler_skipped_when_webhook_empty(): void
    {
        $config = new VortosLoggingConfig();
        $config->slack('');

        $this->assertEmpty($config->toArray()['slack_handlers']);
    }

    public function test_email_handler_registered_when_address_provided(): void
    {
        $config = new VortosLoggingConfig();
        $config->email('alerts@example.com', Level::Error);

        $handlers = $config->toArray()['email_handlers'];
        $this->assertCount(1, $handlers);
        $this->assertSame('alerts@example.com', $handlers[0]['to']);
    }

    public function test_fluent_interface_returns_same_instance(): void
    {
        $config = new VortosLoggingConfig();
        $this->assertSame($config, $config->channel(LogChannel::App, Level::Debug));
        $this->assertSame($config, $config->disableChannel(LogChannel::Cache));
        $this->assertSame($config, $config->rotation(true));
        $this->assertSame($config, $config->buffer(true));
        $this->assertSame($config, $config->correlationId(true));
        $this->assertSame($config, $config->sentry('https://dsn', Level::Error));
        $this->assertSame($config, $config->slack('https://hook', Level::Critical));
        $this->assertSame($config, $config->email('a@b.com', Level::Error));
    }
}
