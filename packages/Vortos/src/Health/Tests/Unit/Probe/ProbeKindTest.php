<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\ProbeKind;

final class ProbeKindTest extends TestCase
{
    public function testAllCasesHaveStringValues(): void
    {
        self::assertSame('liveness', ProbeKind::Liveness->value);
        self::assertSame('readiness', ProbeKind::Readiness->value);
        self::assertSame('startup', ProbeKind::Startup->value);
    }

    public function testCaseCount(): void
    {
        self::assertCount(3, ProbeKind::cases());
    }
}
