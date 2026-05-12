<?php

declare(strict_types=1);

namespace Vortos\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Telemetry\TelemetryLabels;

final class TelemetryLabelsTest extends TestCase
{
    public function test_user_hash_uses_xxh128(): void
    {
        $this->assertSame(hash('xxh128', 'user-123'), TelemetryLabels::userHash('user-123'));
        $this->assertNotSame(hash('sha256', 'user-123'), TelemetryLabels::userHash('user-123'));
    }
}
