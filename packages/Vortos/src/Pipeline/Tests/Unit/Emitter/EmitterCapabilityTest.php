<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Emitter;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Emitter\Capability\EmitterCapability;

final class EmitterCapabilityTest extends TestCase
{
    public function test_key_returns_value_for_each_case(): void
    {
        foreach (EmitterCapability::cases() as $case) {
            $this->assertSame($case->value, $case->key());
        }
    }

    public function test_all_cases_present(): void
    {
        $expected = [
            'build_native_arch',
            'github_actions',
            'gitlab_ci',
            'matrix',
            'oidc',
            'sha_pinning',
            'reusable_workflows',
        ];

        $values = array_map(fn ($c) => $c->value, EmitterCapability::cases());
        sort($values);
        sort($expected);

        $this->assertSame($expected, $values);
    }
}
