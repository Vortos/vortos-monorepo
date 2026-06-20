<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Vortos\Foundation\DependencyInjection\Enum\CompilerPassType;

final class CompilerPassTypeTest extends TestCase
{
    public function test_all_cases_exist(): void
    {
        $cases = CompilerPassType::cases();

        $this->assertCount(5, $cases);

        $names = array_map(fn(CompilerPassType $c) => $c->name, $cases);
        $this->assertContains('BeforeOptimization', $names);
        $this->assertContains('Optimize', $names);
        $this->assertContains('BeforeRemoving', $names);
        $this->assertContains('Remove', $names);
        $this->assertContains('AfterRemoving', $names);
    }

    /** @return array<string, array{CompilerPassType, string}> */
    public static function providePassConfigConstants(): array
    {
        return [
            'BeforeOptimization' => [CompilerPassType::BeforeOptimization, PassConfig::TYPE_BEFORE_OPTIMIZATION],
            'Optimize'           => [CompilerPassType::Optimize,           PassConfig::TYPE_OPTIMIZE],
            'BeforeRemoving'     => [CompilerPassType::BeforeRemoving,     PassConfig::TYPE_BEFORE_REMOVING],
            'Remove'             => [CompilerPassType::Remove,             PassConfig::TYPE_REMOVE],
            'AfterRemoving'      => [CompilerPassType::AfterRemoving,      PassConfig::TYPE_AFTER_REMOVING],
        ];
    }

    #[DataProvider('providePassConfigConstants')]
    public function test_values_match_symfony_pass_config_constants(
        CompilerPassType $case,
        string $symfonyConstant,
    ): void {
        $this->assertSame(
            $symfonyConstant,
            $case->value,
            sprintf('CompilerPassType::%s->value must equal PassConfig constant "%s"', $case->name, $symfonyConstant),
        );
    }

    public function test_from_string_roundtrips(): void
    {
        foreach (CompilerPassType::cases() as $case) {
            $this->assertSame($case, CompilerPassType::from($case->value));
        }
    }

    public function test_default_is_before_optimization(): void
    {
        // BeforeOptimization is the default type used in #[AsCompilerPass].
        // Guard against the Symfony constant value drifting.
        $this->assertSame(
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            CompilerPassType::BeforeOptimization->value,
        );
    }
}
