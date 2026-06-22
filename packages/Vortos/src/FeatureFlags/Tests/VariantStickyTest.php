<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;

final class VariantStickyTest extends TestCase
{
    private FlagEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
    }

    public function test_assignment_is_deterministic(): void
    {
        $flag = $this->flag(['control' => 50, 'treatment' => 50]);
        $ctx  = new FlagContext('stable-user');

        $first = $this->evaluator->evaluateVariant($flag, $ctx);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, $this->evaluator->evaluateVariant($flag, $ctx));
        }
    }

    public function test_growing_a_variant_never_drops_existing_members(): void
    {
        // Sticky property: increasing 'treatment' weight only adds members; nobody who
        // had 'treatment' loses it.
        $before = $this->flag(['control' => 50, 'treatment' => 50]);
        $after  = $this->flag(['control' => 30, 'treatment' => 70]);

        foreach (range(0, 2000) as $i) {
            $ctx = new FlagContext("user-{$i}");
            if ($this->evaluator->evaluateVariant($before, $ctx) === 'treatment') {
                $this->assertSame(
                    'treatment',
                    $this->evaluator->evaluateVariant($after, $ctx),
                    "user-{$i} dropped out of treatment when its weight grew",
                );
            }
        }
    }

    public function test_remainder_below_100_falls_to_control(): void
    {
        $flag  = $this->flag(['treatment' => 10]); // 90% unassigned
        $controls = 0;
        for ($i = 0; $i < 500; $i++) {
            if ($this->evaluator->evaluateVariant($flag, new FlagContext("u-{$i}")) === 'control') {
                $controls++;
            }
        }
        $this->assertGreaterThan(350, $controls); // ~90%
    }

    public function test_control_when_disabled_or_no_variants_or_no_key(): void
    {
        $this->assertSame('control', $this->evaluator->evaluateVariant(
            $this->flag(['a' => 100], enabled: false),
            new FlagContext('u'),
        ));
        $this->assertSame('control', $this->evaluator->evaluateVariant(
            $this->flag(null),
            new FlagContext('u'),
        ));
        $this->assertSame('control', $this->evaluator->evaluateVariant(
            $this->flag(['a' => 100]),
            new FlagContext(), // no userId → no bucket key
        ));
    }

    public function test_per_variant_override_forces_assignment(): void
    {
        $flag = new FeatureFlag(
            'id', 'exp', '', true, [], ['control' => 50, 'treatment' => 50],
            new \DateTimeImmutable(), new \DateTimeImmutable(),
            variantRules: [
                'treatment' => [new FlagRule(FlagRule::TYPE_USERS, users: ['forced-user'])],
            ],
        );

        // Forced regardless of bucket.
        $this->assertSame('treatment', $this->evaluator->evaluateVariant($flag, new FlagContext('forced-user')));
        // Others fall through to weighted assignment (valid bucket).
        $this->assertContains(
            $this->evaluator->evaluateVariant($flag, new FlagContext('someone-else')),
            ['control', 'treatment'],
        );
    }

    private function flag(?array $variants, bool $enabled = true): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id', 'exp', '', $enabled, [], $variants, $now, $now);
    }
}
