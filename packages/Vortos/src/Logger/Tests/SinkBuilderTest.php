<?php

declare(strict_types=1);

namespace Vortos\Logger\Tests;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\Config\BufferPolicy;
use Vortos\Logger\Config\SinkDestination;
use Vortos\Logger\DependencyInjection\SinkBuilder;

final class SinkBuilderTest extends TestCase
{
    public function test_to_file_sets_destination_and_path(): void
    {
        $sink = (new SinkBuilder('app'))->toFile('app.log')->build();

        $this->assertSame(SinkDestination::File, $sink->destination);
        $this->assertSame('app.log', $sink->path);
        $this->assertTrue($sink->rotation->enabled);
    }

    public function test_to_stream_sets_destination_and_disables_rotation(): void
    {
        $sink = (new SinkBuilder('app'))->toStream('php://stderr')->build();

        $this->assertSame(SinkDestination::Stream, $sink->destination);
        $this->assertSame('php://stderr', $sink->path);
        $this->assertFalse($sink->rotation->enabled);
    }

    public function test_to_syslog_sets_destination_and_disables_rotation(): void
    {
        $sink = (new SinkBuilder('app'))->toSyslog('myapp')->build();

        $this->assertSame(SinkDestination::Syslog, $sink->destination);
        $this->assertSame('myapp', $sink->path);
        $this->assertFalse($sink->rotation->enabled);
    }

    public function test_custom_handler_sets_destination_and_disables_rotation(): void
    {
        $sink = (new SinkBuilder('siem'))->customHandler('app.logging.siem_handler')->build();

        $this->assertSame(SinkDestination::Custom, $sink->destination);
        $this->assertSame('app.logging.siem_handler', $sink->customHandlerServiceId);
        $this->assertFalse($sink->rotation->enabled);
    }

    public function test_level_sets_sink_level(): void
    {
        $sink = (new SinkBuilder('app'))->toFile('app.log')->level(Level::Error)->build();

        $this->assertSame(Level::Error, $sink->level);
    }

    public function test_write_through_sets_buffer_policy(): void
    {
        $sink = (new SinkBuilder('audit'))->toFile('audit.log')->writeThrough()->build();

        $this->assertSame(BufferPolicy::WriteThrough, $sink->bufferPolicy);
    }

    public function test_batched_sets_buffer_policy_and_flush_interval(): void
    {
        $sink = (new SinkBuilder('app'))->toFile('app.log')->batched(30)->build();

        $this->assertSame(BufferPolicy::Batched, $sink->bufferPolicy);
        $this->assertSame(30, $sink->flushIntervalSeconds);
    }

    public function test_rotation_overrides_defaults(): void
    {
        $sink = (new SinkBuilder('app'))->toFile('app.log')->rotation(maxFiles: 7, maxAgeDays: 14, maxTotalSizeMb: 256, compress: false)->build();

        $this->assertTrue($sink->rotation->enabled);
        $this->assertSame(7, $sink->rotation->maxFiles);
        $this->assertSame(14, $sink->rotation->maxAgeDays);
        $this->assertSame(256, $sink->rotation->maxTotalSizeMb);
        $this->assertFalse($sink->rotation->compress);
    }

    public function test_rotation_disabled_clears_policy(): void
    {
        $sink = (new SinkBuilder('app'))->toFile('app.log')->rotation(enabled: false)->build();

        $this->assertFalse($sink->rotation->enabled);
    }

    public function test_sample_sets_factor_and_floors_at_one(): void
    {
        $sink = (new SinkBuilder('cache'))->toFile('cache.log')->sample(100)->build();
        $this->assertSame(100, $sink->sampleFactor);

        $sink = (new SinkBuilder('cache'))->toFile('cache.log')->sample(0)->build();
        $this->assertSame(1, $sink->sampleFactor);
    }

    public function test_hash_chain_defaults_off_and_can_be_enabled(): void
    {
        $sink = (new SinkBuilder('audit'))->toFile('audit.log')->build();
        $this->assertFalse($sink->hashChain);

        $sink = (new SinkBuilder('audit'))->toFile('audit.log')->hashChain()->build();
        $this->assertTrue($sink->hashChain);

        $sink = (new SinkBuilder('audit'))->toFile('audit.log')->hashChain(false)->build();
        $this->assertFalse($sink->hashChain);
    }

    public function test_compliance_risk_acknowledgement(): void
    {
        $builder = new SinkBuilder('audit');
        $this->assertFalse($builder->complianceRiskAcknowledged());

        $builder->acknowledgeComplianceRisk();
        $this->assertTrue($builder->complianceRiskAcknowledged());
    }

    public function test_apply_default_destination_if_unset_uses_file_in_dev(): void
    {
        $builder = new SinkBuilder('app');
        $builder->applyDefaultDestinationIfUnset('dev', 'app');

        $sink = $builder->build();
        $this->assertSame(SinkDestination::File, $sink->destination);
        $this->assertSame('app.log', $sink->path);
    }

    public function test_apply_default_destination_if_unset_uses_stream_in_prod(): void
    {
        $builder = new SinkBuilder('app');
        $builder->applyDefaultDestinationIfUnset('prod', 'app');

        $sink = $builder->build();
        $this->assertSame(SinkDestination::Stream, $sink->destination);
        $this->assertSame('php://stderr', $sink->path);
    }

    public function test_apply_default_destination_if_unset_is_noop_when_destination_already_set(): void
    {
        $builder = new SinkBuilder('app');
        $builder->toSyslog('myapp');
        $builder->applyDefaultDestinationIfUnset('prod', 'app');

        $sink = $builder->build();
        $this->assertSame(SinkDestination::Syslog, $sink->destination);
        $this->assertSame('myapp', $sink->path);
    }

    public function test_fluent_interface_returns_same_instance(): void
    {
        $builder = new SinkBuilder('app');

        $this->assertSame($builder, $builder->toFile('app.log'));
        $this->assertSame($builder, $builder->level(Level::Error));
        $this->assertSame($builder, $builder->writeThrough());
        $this->assertSame($builder, $builder->batched());
        $this->assertSame($builder, $builder->rotation());
        $this->assertSame($builder, $builder->sample(10));
        $this->assertSame($builder, $builder->hashChain());
        $this->assertSame($builder, $builder->acknowledgeComplianceRisk());
    }

    public function test_build_returns_sink_definition_with_id(): void
    {
        $sink = (new SinkBuilder('app'))->toFile('app.log')->build();

        $this->assertSame('app', $sink->id);
    }
}
