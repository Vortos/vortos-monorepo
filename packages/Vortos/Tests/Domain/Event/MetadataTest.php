<?php

declare(strict_types=1);

namespace Vortos\Tests\Domain\Event;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\Metadata;

final class MetadataTest extends TestCase
{
    public function test_empty_returns_metadata_with_all_nulls(): void
    {
        $metadata = Metadata::empty();

        $this->assertNull($metadata->correlationId);
        $this->assertNull($metadata->causationId);
        $this->assertNull($metadata->traceId);
        $this->assertNull($metadata->tenantId);
        $this->assertNull($metadata->userId);
        $this->assertSame([], $metadata->custom);
    }

    public function test_default_constructor_matches_empty(): void
    {
        $a = new Metadata();
        $b = Metadata::empty();

        $this->assertEquals($a, $b);
    }

    public function test_all_fields_populated(): void
    {
        $metadata = new Metadata(
            correlationId: 'corr-1',
            causationId:   'cause-1',
            traceId:       'trace-1',
            tenantId:      'tenant-1',
            userId:        'user-1',
            custom:        ['extra' => 'value', 'flag' => true],
        );

        $this->assertSame('corr-1', $metadata->correlationId);
        $this->assertSame('cause-1', $metadata->causationId);
        $this->assertSame('trace-1', $metadata->traceId);
        $this->assertSame('tenant-1', $metadata->tenantId);
        $this->assertSame('user-1', $metadata->userId);
        $this->assertSame(['extra' => 'value', 'flag' => true], $metadata->custom);
    }

    public function test_partial_population_keeps_other_fields_null(): void
    {
        $metadata = new Metadata(correlationId: 'corr-1', tenantId: 'tenant-1');

        $this->assertSame('corr-1', $metadata->correlationId);
        $this->assertSame('tenant-1', $metadata->tenantId);
        $this->assertNull($metadata->causationId);
        $this->assertNull($metadata->traceId);
        $this->assertNull($metadata->userId);
        $this->assertSame([], $metadata->custom);
    }

    public function test_metadata_is_readonly(): void
    {
        $metadata = new Metadata(correlationId: 'corr-1');

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line — intentional assignment to verify readonly */
        $metadata->correlationId = 'mutated';
    }
}
