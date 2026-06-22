<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Layer;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exception\InvalidFlagException;
use Vortos\FeatureFlags\Layer\InMemoryLayerStorage;
use Vortos\FeatureFlags\Layer\Layer;
use Vortos\FeatureFlags\Layer\LayerMember;
use Vortos\FeatureFlags\Layer\Validation\LayerValidator;
use Vortos\FeatureFlags\Targeting\Bucketing;

final class LayerValidatorTest extends TestCase
{
    private InMemoryLayerStorage $storage;
    private LayerValidator $validator;

    protected function setUp(): void
    {
        $this->storage   = new InMemoryLayerStorage();
        $this->validator = new LayerValidator($this->storage);
    }

    public function test_valid_layer_passes_validation(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'l1', name: 'my-layer', salt: 'my-salt',
            holdoutWeight: 1000,
            memberWeights: ['exp-a' => 3000, 'exp-b' => 3000],
        );

        $this->validator->validate($layer); // Should not throw
        $this->assertSame(7000, $layer->totalAllocated());
    }

    public function test_over_allocated_layer_is_rejected(): void
    {
        $this->expectException(InvalidFlagException::class);
        $this->expectExceptionMessage('over-allocated');

        LayerValidator::buildLayer(
            id: 'l-over', name: 'over', salt: 'over-salt',
            holdoutWeight: 1000,
            memberWeights: ['exp-a' => 5000, 'exp-b' => 5000], // 1000+5000+5000 = 11000 > 10000
        );
    }

    public function test_exact_full_allocation_passes(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'l-full', name: 'full', salt: 'full-salt',
            holdoutWeight: 0,
            memberWeights: ['exp-a' => 5000, 'exp-b' => 5000], // exactly 10000
        );

        $this->validator->validate($layer);
        $this->assertSame(Bucketing::BUCKETS, $layer->totalAllocated());
    }

    public function test_flag_in_two_layers_is_rejected(): void
    {
        $layer1 = LayerValidator::buildLayer(
            id: 'l1', name: 'layer-1', salt: 'salt-1',
            holdoutWeight: 0,
            memberWeights: ['shared-flag' => 5000],
        );
        $this->storage->save($layer1);

        $layer2 = LayerValidator::buildLayer(
            id: 'l2', name: 'layer-2', salt: 'salt-2',
            holdoutWeight: 0,
            memberWeights: ['shared-flag' => 3000], // same flag → violation
        );

        $this->expectException(InvalidFlagException::class);
        $this->expectExceptionMessage('shared-flag');
        $this->validator->validate($layer2);
    }

    public function test_updating_same_layer_allows_flag_in_its_own_layer(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'l-self', name: 'self-layer', salt: 'self-salt',
            holdoutWeight: 0,
            memberWeights: ['my-flag' => 5000],
        );
        $this->storage->save($layer);

        // Re-validating the same layer (same id) should not fail on "already in layer"
        $updatedLayer = LayerValidator::buildLayer(
            id: 'l-self', name: 'self-layer', salt: 'self-salt',
            holdoutWeight: 0,
            memberWeights: ['my-flag' => 7000],
        );
        $this->validator->validate($updatedLayer); // Should not throw
        $this->assertSame(7000, $updatedLayer->totalAllocated());
    }

    public function test_empty_name_is_rejected(): void
    {
        $this->expectException(InvalidFlagException::class);
        $this->expectExceptionMessage('name');

        $layer = new Layer('id', '', 'salt', 0, []);
        $this->validator->validate($layer);
    }

    public function test_empty_salt_is_rejected(): void
    {
        $this->expectException(InvalidFlagException::class);
        $this->expectExceptionMessage('salt');

        $layer = new Layer('id', 'name', '', 0, []);
        $this->validator->validate($layer);
    }

    public function test_layer_member_rejects_invalid_slice_bounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LayerMember('flag', 9000, 2000); // 9000+2000 = 11000 > 10000
    }

    public function test_holdout_covers_full_space_no_members(): void
    {
        $layer = new Layer('l', 'holdout-only', 'salt', Bucketing::BUCKETS, []);
        $this->validator->validate($layer);
        $this->assertTrue($layer->isHoldout(0));
        $this->assertTrue($layer->isHoldout(9999));
    }

    public function test_build_layer_factory_assigns_contiguous_slices(): void
    {
        $layer = LayerValidator::buildLayer(
            id: 'l-slots', name: 'slots', salt: 'slots-salt',
            holdoutWeight: 500,
            memberWeights: ['exp-1' => 2000, 'exp-2' => 3000, 'exp-3' => 1500],
        );

        $members = $layer->members;
        $this->assertCount(3, $members);
        $this->assertSame(500, $members[0]->sliceStart);
        $this->assertSame(2000, $members[0]->weight);
        $this->assertSame(2500, $members[1]->sliceStart);
        $this->assertSame(3000, $members[1]->weight);
        $this->assertSame(5500, $members[2]->sliceStart);
        $this->assertSame(1500, $members[2]->weight);
    }
}
