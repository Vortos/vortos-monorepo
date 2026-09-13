<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Gate;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Gate\GateDisposition;

final class GateDispositionTest extends TestCase
{
    public function test_a_blocking_failure_stops_a_release_in_every_mode(): void
    {
        self::assertTrue(GateDisposition::Blocking->blocksRelease());
        self::assertTrue(GateDisposition::Blocking->blocksRelease(strict: true));
    }

    public function test_an_advisory_failure_stops_a_release_only_when_the_operator_asks(): void
    {
        self::assertFalse(GateDisposition::Advisory->blocksRelease());
        self::assertTrue(GateDisposition::Advisory->blocksRelease(strict: true));
    }

    public function test_the_wire_values_are_stable(): void
    {
        // Emitted in deploy:doctor --json and scheduler:doctor --json; CI parses them.
        self::assertSame('blocking', GateDisposition::Blocking->value);
        self::assertSame('advisory', GateDisposition::Advisory->value);
        self::assertCount(2, GateDisposition::cases(), 'a third disposition needs every consumer of the JSON reviewed');
    }
}
