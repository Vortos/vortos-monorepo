<?php

declare(strict_types=1);

namespace Vortos\Logger\Tests;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\Config\BufferPolicy;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\Config\SinkDestination;
use Vortos\Logger\DependencyInjection\VortosLoggingConfig;
use Vortos\Logger\Exception\InvalidLoggingConfigException;
use Vortos\Observability\Config\ObservabilityModule;

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

    public function test_every_framework_channel_gets_a_default_sink_in_prod(): void
    {
        $resolved = (new VortosLoggingConfig())->resolve();

        foreach (LogChannel::cases() as $channel) {
            $this->assertArrayHasKey($channel->value, $resolved->channels);
            $this->assertArrayHasKey($channel->value, $resolved->sinks);

            $sink = $resolved->sinks[$channel->value];
            $this->assertSame(SinkDestination::Stream, $sink->destination);
            $this->assertSame('php://stderr', $sink->path);
        }
    }

    public function test_every_framework_channel_gets_a_default_file_sink_in_dev(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $resolved = (new VortosLoggingConfig('dev'))->resolve();

        $sink = $resolved->sinks[LogChannel::Http->value];
        $this->assertSame(SinkDestination::File, $sink->destination);
        $this->assertSame(LogChannel::Http->value . '.log', $sink->path);
        $this->assertTrue($sink->rotation->enabled);
        $this->assertSame(Level::Debug, $resolved->channels[LogChannel::Http->value]->level);
    }

    public function test_security_and_audit_channels_default_to_write_through(): void
    {
        $resolved = (new VortosLoggingConfig())->resolve();

        $this->assertSame(BufferPolicy::WriteThrough, $resolved->sinks[LogChannel::Security->value]->bufferPolicy);
        $this->assertSame(BufferPolicy::WriteThrough, $resolved->sinks[LogChannel::Audit->value]->bufferPolicy);
    }

    public function test_other_channels_default_to_batched(): void
    {
        $resolved = (new VortosLoggingConfig())->resolve();

        $this->assertSame(BufferPolicy::Batched, $resolved->sinks[LogChannel::App->value]->bufferPolicy);
        $this->assertSame(BufferPolicy::Batched, $resolved->sinks[LogChannel::Cache->value]->bufferPolicy);
    }

    public function test_audit_channel_defaults_to_hash_chain_and_floor_retention(): void
    {
        $resolved = (new VortosLoggingConfig())->resolve();

        $auditSink = $resolved->sinks[LogChannel::Audit->value];
        $this->assertTrue($auditSink->hashChain);
        $this->assertSame(365, $auditSink->rotation->maxAgeDays);
    }

    public function test_default_levels_are_warning_for_app_and_error_for_others_in_prod(): void
    {
        $resolved = (new VortosLoggingConfig())->resolve();

        $this->assertSame(Level::Warning, $resolved->channels[LogChannel::App->value]->level);
        $this->assertSame(Level::Error, $resolved->channels[LogChannel::Http->value]->level);
    }

    public function test_can_set_channel_level(): void
    {
        $config = new VortosLoggingConfig();
        $config->channel(LogChannel::Messaging)->level(Level::Warning);

        $resolved = $config->resolve();
        $this->assertSame(Level::Warning, $resolved->channels[LogChannel::Messaging->value]->level);
    }

    public function test_can_disable_framework_channel(): void
    {
        $config = new VortosLoggingConfig();
        $config->channel(LogChannel::Cache)->disable();
        $config->channel(LogChannel::Query)->disable();

        $resolved = $config->resolve();
        $this->assertTrue($resolved->channels[LogChannel::Cache->value]->disabled);
        $this->assertTrue($resolved->channels[LogChannel::Query->value]->disabled);
        $this->assertSame([], $resolved->channels[LogChannel::Cache->value]->sinkIds);
    }

    public function test_disable_channel_legacy_helper(): void
    {
        $config = new VortosLoggingConfig();
        $config->disableChannel(LogChannel::Cache, LogChannel::Query);

        $resolved = $config->resolve();
        $this->assertTrue($resolved->channels[LogChannel::Cache->value]->disabled);
        $this->assertTrue($resolved->channels[LogChannel::Query->value]->disabled);
    }

    public function test_can_disable_framework_module_logs(): void
    {
        $config = new VortosLoggingConfig();
        $config->disableModule(ObservabilityModule::Make, ObservabilityModule::Persistence);

        $resolved = $config->resolve();
        $this->assertTrue($resolved->channels[LogChannel::Tooling->value]->disabled);
        $this->assertTrue($resolved->channels[LogChannel::Query->value]->disabled);
    }

    public function test_app_channel_cannot_be_disabled(): void
    {
        $config = new VortosLoggingConfig();
        $config->disableChannel(LogChannel::App);

        $resolved = $config->resolve();
        $this->assertFalse($resolved->channels[LogChannel::App->value]->disabled);
    }

    public function test_can_route_channel_to_multiple_sinks(): void
    {
        $config = new VortosLoggingConfig();
        $config->sink('siem')->customHandler('app.logging.siem_handler');
        $config->channel(LogChannel::Security)->alsoRouteTo('siem');

        $resolved = $config->resolve();
        $this->assertSame([LogChannel::Security->value, 'siem'], $resolved->channels[LogChannel::Security->value]->sinkIds);
        $this->assertSame(SinkDestination::Custom, $resolved->sinks['siem']->destination);
        $this->assertSame('app.logging.siem_handler', $resolved->sinks['siem']->customHandlerServiceId);
    }

    public function test_route_to_replaces_default_routing(): void
    {
        $config = new VortosLoggingConfig();
        $config->sink('siem')->customHandler('app.logging.siem_handler');
        $config->channel(LogChannel::Security)->routeTo('siem');

        $resolved = $config->resolve();
        $this->assertSame(['siem'], $resolved->channels[LogChannel::Security->value]->sinkIds);
    }

    public function test_sink_sampling(): void
    {
        $config = new VortosLoggingConfig();
        $config->sink(LogChannel::Cache->value)->sample(100);

        $resolved = $config->resolve();
        $this->assertSame(100, $resolved->sinks[LogChannel::Cache->value]->sampleFactor);
    }

    public function test_unknown_sink_reference_throws(): void
    {
        $config = new VortosLoggingConfig();
        $config->channel(LogChannel::Security)->routeTo('does-not-exist');

        $this->expectException(InvalidLoggingConfigException::class);
        $config->resolve();
    }

    public function test_audit_retention_below_floor_throws_without_acknowledgement(): void
    {
        $config = new VortosLoggingConfig();
        $config->sink(LogChannel::Audit->value)->toFile('audit.log')->rotation(maxAgeDays: 30);

        $this->expectException(InvalidLoggingConfigException::class);
        $config->resolve();
    }

    public function test_audit_retention_below_floor_allowed_with_acknowledgement(): void
    {
        $config = new VortosLoggingConfig();
        $config->sink(LogChannel::Audit->value)->toFile('audit.log')->rotation(maxAgeDays: 30)->acknowledgeComplianceRisk();

        $resolved = $config->resolve();
        $this->assertSame(30, $resolved->sinks[LogChannel::Audit->value]->rotation->maxAgeDays);
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

        $resolved = $config->resolve();

        $this->assertTrue($resolved->introspection);
        $this->assertSame(['secret'], $resolved->redactionKeys);
        $this->assertFalse($resolved->structured);
        $this->assertFalse($resolved->requestContext);
        $this->assertSame('checkout', $resolved->serviceName);
        $this->assertSame('1.2.3', $resolved->serviceVersion);
        $this->assertSame('prod', $resolved->deploymentEnvironment);
        $this->assertFalse($resolved->failOnMissingIntegrations);
    }

    public function test_sentry_handler_registered_when_dsn_provided(): void
    {
        $config = new VortosLoggingConfig();
        $config->sentry('https://key@sentry.io/123', Level::Critical);

        $handlers = $config->resolve()->sentryHandlers;
        $this->assertCount(1, $handlers);
        $this->assertSame('https://key@sentry.io/123', $handlers[0]['dsn']);
        $this->assertSame(Level::Critical, $handlers[0]['minLevel']);
    }

    public function test_sentry_handler_skipped_when_dsn_empty(): void
    {
        $config = new VortosLoggingConfig();
        $config->sentry('');

        $this->assertEmpty($config->resolve()->sentryHandlers);
    }

    public function test_slack_handler_registered_when_webhook_provided(): void
    {
        $config = new VortosLoggingConfig();
        $config->slack('https://hooks.slack.com/xxx', Level::Critical);

        $handlers = $config->resolve()->slackHandlers;
        $this->assertCount(1, $handlers);
        $this->assertSame('https://hooks.slack.com/xxx', $handlers[0]['webhook']);
    }

    public function test_email_handler_registered_when_address_provided(): void
    {
        $config = new VortosLoggingConfig();
        $config->email('alerts@example.com', Level::Error);

        $handlers = $config->resolve()->emailHandlers;
        $this->assertCount(1, $handlers);
        $this->assertSame('alerts@example.com', $handlers[0]['to']);
    }

    public function test_flush_interval_applies_to_default_batched_sinks(): void
    {
        $config = new VortosLoggingConfig();
        $config->flushInterval(10);

        $resolved = $config->resolve();
        $this->assertSame(10, $resolved->sinks[LogChannel::App->value]->flushIntervalSeconds);
    }

    public function test_fluent_interface_returns_same_instance(): void
    {
        $config = new VortosLoggingConfig();
        $this->assertSame($config, $config->disableChannel(LogChannel::Cache));
        $this->assertSame($config, $config->flushInterval(5));
        $this->assertSame($config, $config->correlationId(true));
        $this->assertSame($config, $config->sentry('https://dsn', Level::Error));
        $this->assertSame($config, $config->slack('https://hook', Level::Critical));
        $this->assertSame($config, $config->email('a@b.com', Level::Error));
    }
}
