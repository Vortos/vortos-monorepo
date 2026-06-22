<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Layer;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\Layer\InMemoryLayerStorage;
use Vortos\FeatureFlags\Layer\Layer;
use Vortos\FeatureFlags\Layer\LayerEvaluator;
use Vortos\FeatureFlags\Layer\LayerMember;
use Vortos\FeatureFlags\Layer\Validation\LayerValidator;
use Vortos\FeatureFlags\Targeting\Bucketing;

final class LayerEvaluatorTest extends TestCase
{
    private InMemoryLayerStorage $storage;
    private LayerEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->storage   = new InMemoryLayerStorage();
        $this->evaluator = new LayerEvaluator($this->storage);
    }

    private function makeFlag(string $name, string $layerId): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag('id-' . $name, $name, '', true, [], null, $now, $now, layerId: $layerId);
    }

    public function test_is_in_slice_returns_true_for_user_in_slice(): void
    {
        $salt = 'slice-test-salt';

        // Find a user that falls into bucket range [0, 5000)
        $userId = null;
        for ($i = 0; $i < 1000; $i++) {
            $bucket = Bucketing::bucket($salt, "user-{$i}");
            if ($bucket >= 0 && $bucket < 5000) {
                $userId = "user-{$i}";
                break;
            }
        }

        $this->assertNotNull($userId, 'Could not find a user in slice [0,5000) — increase search range');

        $layer = new Layer('l1', 'test', $salt, 0, [
            new LayerMember('flag-a', 0, 5000),
            new LayerMember('flag-b', 5000, 5000),
        ]);
        $this->storage->save($layer);

        $flag = $this->makeFlag('flag-a', 'l1');
        $ctx  = new FlagContext($userId);

        $this->assertTrue($this->evaluator->isInSlice($flag, $ctx));
    }

    public function test_is_in_slice_returns_false_for_user_in_other_slice(): void
    {
        $salt = 'other-slice-salt';

        // Find a user in bucket range [5000, 10000)
        $userId = null;
        for ($i = 0; $i < 1000; $i++) {
            $bucket = Bucketing::bucket($salt, "user-{$i}");
            if ($bucket >= 5000) {
                $userId = "user-{$i}";
                break;
            }
        }

        $this->assertNotNull($userId);

        $layer = new Layer('l2', 'other', $salt, 0, [
            new LayerMember('flag-a', 0, 5000),
            new LayerMember('flag-b', 5000, 5000),
        ]);
        $this->storage->save($layer);

        $flag = $this->makeFlag('flag-a', 'l2'); // flag-a is in [0,5000)
        $ctx  = new FlagContext($userId);         // but user is in [5000, 10000)

        $this->assertFalse($this->evaluator->isInSlice($flag, $ctx));
    }

    public function test_is_in_slice_false_for_flag_without_layer_id(): void
    {
        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag('id', 'no-layer', '', true, [], null, $now, $now); // layerId = null
        $ctx  = new FlagContext('user-1');

        $this->assertFalse($this->evaluator->isInSlice($flag, $ctx));
    }

    public function test_is_in_slice_false_for_missing_layer(): void
    {
        $flag = $this->makeFlag('orphan', 'nonexistent-layer');
        $ctx  = new FlagContext('user-1');

        $this->assertFalse($this->evaluator->isInSlice($flag, $ctx));
    }

    public function test_is_in_slice_false_for_null_context_key(): void
    {
        $layer = LayerValidator::buildLayer('l3', 'test-ctx', 'ctx-salt', 0, ['my-flag' => 10000]);
        $this->storage->save($layer);

        $flag = $this->makeFlag('my-flag', 'l3');
        $ctx  = new FlagContext(null); // no userId → no bucket key

        $this->assertFalse($this->evaluator->isInSlice($flag, $ctx));
    }

    public function test_winner_for_context_returns_correct_member(): void
    {
        $salt  = 'winner-test-salt';
        $layer = new Layer('lw', 'winner', $salt, 0, [
            new LayerMember('exp-control', 0, 5000),
            new LayerMember('exp-treatment', 5000, 5000),
        ]);
        $this->storage->save($layer);

        // Find a user and compute expected winner
        $userId   = 'winner-user-7';
        $bucket   = Bucketing::bucket($salt, $userId);
        $expected = $bucket < 5000 ? 'exp-control' : 'exp-treatment';

        $winner = $this->evaluator->winnerForContext($layer, new FlagContext($userId), 'userId');

        $this->assertNotNull($winner);
        $this->assertSame($expected, $winner->flagName);
    }

    public function test_winner_for_context_returns_null_for_holdout(): void
    {
        $salt        = 'holdout-winner-salt';
        $holdoutMax  = Bucketing::BUCKETS; // 100% holdout → all users in holdout
        $layer       = new Layer('lho', 'holdout-winner', $salt, $holdoutMax, []);
        $this->storage->save($layer);

        $winner = $this->evaluator->winnerForContext($layer, new FlagContext('user-x'), 'userId');
        $this->assertNull($winner);
    }

    public function test_holdout_users_appear_in_holdout_range(): void
    {
        $salt  = 'partial-holdout-salt';
        $layer = LayerValidator::buildLayer('lph', 'partial-holdout', $salt, 2000, ['exp' => 8000]);
        $this->storage->save($layer);

        $holdoutCount = 0;
        $expCount     = 0;

        for ($i = 0; $i < 500; $i++) {
            $ctx    = new FlagContext("user-{$i}");
            $winner = $this->evaluator->winnerForContext($layer, $ctx, 'userId');

            if ($winner === null) {
                $holdoutCount++;
            } else {
                $expCount++;
            }
        }

        // 20% holdout → approximately 100 of 500; 80% experiment → approximately 400 of 500
        $this->assertGreaterThan(50, $holdoutCount, 'Holdout count too low');
        $this->assertGreaterThan(300, $expCount, 'Experiment count too low');
    }
}
