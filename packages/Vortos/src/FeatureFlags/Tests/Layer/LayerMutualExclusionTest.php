<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Layer;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Layer\InMemoryLayerStorage;
use Vortos\FeatureFlags\Layer\Layer;
use Vortos\FeatureFlags\Layer\LayerEvaluator;
use Vortos\FeatureFlags\Layer\LayerMember;
use Vortos\FeatureFlags\Layer\Validation\LayerValidator;
use Vortos\FeatureFlags\Targeting\Bucketing;

/**
 * Block 30 — Mutual exclusion and holdout invariants.
 */
final class LayerMutualExclusionTest extends TestCase
{
    private InMemoryLayerStorage $storage;
    private LayerEvaluator $layerEval;
    private FlagEvaluator $flagEval;

    protected function setUp(): void
    {
        $this->storage  = new InMemoryLayerStorage();
        $this->layerEval = new LayerEvaluator($this->storage);
        $this->flagEval  = new FlagEvaluator(layers: $this->layerEval);
    }

    // -------------------------------------------------------------------------
    // Mutual exclusion: at most one experiment fires per context per layer
    // -------------------------------------------------------------------------

    public function test_at_most_one_experiment_fires_for_any_user(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'layer-1', name: 'checkout-exp', salt: 'checkout-layer',
            holdoutWeight: 0,
            memberWeights: ['flag-a' => 3000, 'flag-b' => 3000, 'flag-c' => 3000],
        );
        $this->storage->save($layer);

        $now   = new \DateTimeImmutable();
        $flagA = $this->makeLayeredFlag('flag-a', 'layer-1', $now);
        $flagB = $this->makeLayeredFlag('flag-b', 'layer-1', $now);
        $flagC = $this->makeLayeredFlag('flag-c', 'layer-1', $now);

        $violations = 0;
        $total      = 500;

        for ($i = 0; $i < $total; $i++) {
            $context = new FlagContext("user-{$i}");
            $aOn     = $this->flagEval->evaluate($flagA, $context);
            $bOn     = $this->flagEval->evaluate($flagB, $context);
            $cOn     = $this->flagEval->evaluate($flagC, $context);

            $fired = ($aOn ? 1 : 0) + ($bOn ? 1 : 0) + ($cOn ? 1 : 0);

            if ($fired > 1) {
                $violations++;
            }
        }

        $this->assertSame(0, $violations, 'Multiple experiments fired for the same user in the same layer');
    }

    public function test_users_distribute_across_slices(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'layer-dist', name: 'dist-exp', salt: 'dist-layer',
            holdoutWeight: 0,
            memberWeights: ['exp-a' => 5000, 'exp-b' => 5000],
        );
        $this->storage->save($layer);

        $now   = new \DateTimeImmutable();
        $flagA = $this->makeLayeredFlag('exp-a', 'layer-dist', $now);
        $flagB = $this->makeLayeredFlag('exp-b', 'layer-dist', $now);

        $aCount = 0;
        $bCount = 0;

        for ($i = 0; $i < 1000; $i++) {
            $ctx = new FlagContext("user-{$i}");
            if ($this->flagEval->evaluate($flagA, $ctx)) {
                $aCount++;
            }
            if ($this->flagEval->evaluate($flagB, $ctx)) {
                $bCount++;
            }
        }

        // With 50/50 split, expect roughly 500 each (within 10% tolerance)
        $this->assertGreaterThan(400, $aCount, 'exp-a fired too rarely');
        $this->assertGreaterThan(400, $bCount, 'exp-b fired too rarely');
        $this->assertEqualsWithDelta(1000, $aCount + $bCount, 10, 'Total fires should equal population (no gap)');
    }

    // -------------------------------------------------------------------------
    // Holdout: cohort always receives control, sticky across ramp changes
    // -------------------------------------------------------------------------

    public function test_holdout_cohort_never_receives_treatment(): void
    {
        // 20% holdout, 40% exp-a, 40% exp-b
        $layer = LayerValidator::buildLayer(
            id: 'layer-h', name: 'holdout-exp', salt: 'holdout-layer',
            holdoutWeight: 2000,
            memberWeights: ['holdout-flag-a' => 4000, 'holdout-flag-b' => 4000],
        );
        $this->storage->save($layer);

        $now   = new \DateTimeImmutable();
        $flagA = $this->makeLayeredFlag('holdout-flag-a', 'layer-h', $now);
        $flagB = $this->makeLayeredFlag('holdout-flag-b', 'layer-h', $now);

        $holdoutFires = 0;

        for ($i = 0; $i < 500; $i++) {
            $userId  = "user-{$i}";
            $bucket  = Bucketing::bucket('holdout-layer', $userId);
            $isHoldout = $bucket < 2000;

            $ctx = new FlagContext($userId);
            $aOn = $this->flagEval->evaluate($flagA, $ctx);
            $bOn = $this->flagEval->evaluate($flagB, $ctx);

            if ($isHoldout && ($aOn || $bOn)) {
                $holdoutFires++;
            }
        }

        $this->assertSame(0, $holdoutFires, 'Holdout cohort must never receive any treatment');
    }

    public function test_holdout_is_sticky_across_ramp_changes(): void
    {
        // Set up a layer and identify which users are in holdout
        $salt   = 'sticky-holdout';
        $userId = 'sticky-user-99';
        $bucket = Bucketing::bucket($salt, $userId);

        // Build the layer such that this user is in the holdout range
        $holdoutWeight = min($bucket + 1, 5000); // ensure user is in holdout

        $layer = new Layer('lh', 'sticky', $salt, $holdoutWeight, [
            new LayerMember('sticky-exp', $holdoutWeight, 5000),
        ]);
        $this->storage->save($layer);

        $now  = new \DateTimeImmutable();
        $flag = $this->makeLayeredFlag('sticky-exp', 'lh', $now);
        $ctx  = new FlagContext($userId);

        $isHoldout = $bucket < $holdoutWeight;

        if ($isHoldout) {
            $this->assertFalse($this->flagEval->evaluate($flag, $ctx), 'Holdout user must not receive treatment');

            // Now "increase the ramp" — the layer weight grows, but holdout range stays.
            // Cap to available bucket space so the LayerMember constructor doesn't reject it.
            $grownWeight  = min(7000, \Vortos\FeatureFlags\Targeting\Bucketing::BUCKETS - $holdoutWeight);
            $updatedLayer = new Layer('lh', 'sticky', $salt, $holdoutWeight, [
                new LayerMember('sticky-exp', $holdoutWeight, $grownWeight),
            ]);
            $this->storage->save($updatedLayer);

            $this->assertFalse($this->flagEval->evaluate($flag, $ctx), 'Holdout must remain stable after ramp change');
        } else {
            // User is in the experiment — they should still be in it after ramp
            $this->markTestSkipped('User not in holdout for this salt; deterministic test requires specific bucket position');
        }
    }

    // -------------------------------------------------------------------------
    // Safe-defaults
    // -------------------------------------------------------------------------

    public function test_missing_layer_config_safe_defaults_to_false(): void
    {
        $now  = new \DateTimeImmutable();
        // Flag claims to be in a layer that doesn't exist
        $flag = $this->makeLayeredFlag('orphan-flag', 'nonexistent-layer', $now);
        $ctx  = new FlagContext('user-1');

        $this->assertFalse($this->flagEval->evaluate($flag, $ctx));
    }

    public function test_flag_not_in_layer_members_safe_defaults(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'layer-x', name: 'exp-x', salt: 'exp-x-salt',
            holdoutWeight: 0,
            memberWeights: ['some-other-flag' => 5000],
        );
        $this->storage->save($layer);

        $now  = new \DateTimeImmutable();
        $flag = $this->makeLayeredFlag('unlisted-flag', 'layer-x', $now); // not in layer members
        $ctx  = new FlagContext('user-1');

        $this->assertFalse($this->flagEval->evaluate($flag, $ctx));
    }

    public function test_anonymous_context_without_bucket_key_safe_defaults(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'layer-anon', name: 'anon-exp', salt: 'anon-salt',
            holdoutWeight: 0,
            memberWeights: ['anon-flag' => 10000],
        );
        $this->storage->save($layer);

        $now  = new \DateTimeImmutable();
        $flag = $this->makeLayeredFlag('anon-flag', 'layer-anon', $now);
        $ctx  = new FlagContext(null); // no userId → no bucket key

        $this->assertFalse($this->flagEval->evaluate($flag, $ctx));
    }

    public function test_non_layered_flag_is_unaffected_by_layer_evaluator(): void
    {
        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag('id', 'regular', '', true, [], null, $now, $now);
        $ctx  = new FlagContext('user-1');

        // LayerEvaluator is wired but this flag has no layerId → normal eval
        $this->assertTrue($this->flagEval->evaluate($flag, $ctx));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeLayeredFlag(string $name, string $layerId, \DateTimeImmutable $now): FeatureFlag
    {
        return new FeatureFlag(
            id: 'id-' . $name,
            name: $name,
            description: '',
            enabled: true,
            rules: [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 100)],
            variants: null,
            createdAt: $now,
            updatedAt: $now,
            layerId: $layerId,
        );
    }
}
