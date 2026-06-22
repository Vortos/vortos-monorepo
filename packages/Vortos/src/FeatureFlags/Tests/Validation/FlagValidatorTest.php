<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exception\InvalidFlagException;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Prerequisite;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Validation\FlagValidator;

final class FlagValidatorTest extends TestCase
{
    // --- prerequisite cycles ---

    public function test_self_prerequisite_is_rejected(): void
    {
        $validator = $this->validator([]);
        $flag      = $this->flag('x', prerequisites: [Prerequisite::on('x')]);

        $this->expectException(InvalidFlagException::class);
        $validator->validate($flag);
    }

    public function test_direct_cycle_is_rejected(): void
    {
        // Stored: a requires b. Now saving b requires a → a→b→a.
        $a         = $this->flag('a', prerequisites: [Prerequisite::on('b')]);
        $validator = $this->validator(['a' => $a]);
        $b         = $this->flag('b', prerequisites: [Prerequisite::on('a')]);

        $this->expectException(InvalidFlagException::class);
        $validator->validate($b);
    }

    public function test_indirect_cycle_is_rejected(): void
    {
        // Stored: b→c, c→a. Saving a→b closes a→b→c→a.
        $b         = $this->flag('b', prerequisites: [Prerequisite::on('c')]);
        $c         = $this->flag('c', prerequisites: [Prerequisite::on('a')]);
        $validator = $this->validator(['b' => $b, 'c' => $c]);
        $a         = $this->flag('a', prerequisites: [Prerequisite::on('b')]);

        $this->expectException(InvalidFlagException::class);
        $validator->validate($a);
    }

    public function test_acyclic_prerequisites_pass(): void
    {
        $a         = $this->flag('a');
        $b         = $this->flag('b', prerequisites: [Prerequisite::on('a')]);
        $validator = $this->validator(['a' => $a, 'b' => $b]);
        $c         = $this->flag('c', prerequisites: [Prerequisite::on('b')]);

        $validator->validate($c);
        $this->addToAssertionCount(1);
    }

    public function test_missing_prerequisite_target_is_not_a_cycle(): void
    {
        $validator = $this->validator([]);
        $validator->validate($this->flag('x', prerequisites: [Prerequisite::on('not-stored')]));
        $this->addToAssertionCount(1);
    }

    // --- permission targeting guard ---

    public function test_permission_flag_rejects_untrusted_attribute_rule(): void
    {
        $validator = $this->validator([]);
        $flag      = $this->flag('gate', kind: FlagKind::Permission, rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro', zone: FlagRule::ZONE_UNTRUSTED),
        ]);

        $this->expectException(InvalidFlagException::class);
        $validator->validate($flag);
    }

    public function test_permission_flag_rejects_any_zone_attribute_rule(): void
    {
        $validator = $this->validator([]);
        $flag      = $this->flag('gate', kind: FlagKind::Permission, rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro'),
        ]);

        $this->expectException(InvalidFlagException::class);
        $validator->validate($flag);
    }

    public function test_permission_flag_accepts_trusted_attribute_rule(): void
    {
        $validator = $this->validator([]);
        $flag      = $this->flag('gate', kind: FlagKind::Permission, rules: [
            new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro', zone: FlagRule::ZONE_TRUSTED),
        ]);

        $validator->validate($flag);
        $this->addToAssertionCount(1);
    }

    public function test_permission_flag_rejects_segment_reference(): void
    {
        $validator = $this->validator([]);
        $flag      = $this->flag('gate', kind: FlagKind::Permission, rules: [
            new FlagRule(FlagRule::TYPE_SEGMENT, segment: 'some-audience'),
        ]);

        $this->expectException(InvalidFlagException::class);
        $validator->validate($flag);
    }

    public function test_permission_flag_rejects_untrusted_bucketing(): void
    {
        $validator = $this->validator([]);
        $flag      = new FeatureFlag(
            'id', 'gate', '', true, [], null, new \DateTimeImmutable(), new \DateTimeImmutable(),
            kind: FlagKind::Permission, bucketBy: FeatureFlag::BUCKET_BY_DEVICE,
        );

        $this->expectException(InvalidFlagException::class);
        $validator->validate($flag);
    }

    public function test_untrusted_rule_in_nested_group_is_rejected_for_permission(): void
    {
        $validator = $this->validator([]);
        $flag      = $this->flag('gate', kind: FlagKind::Permission, rules: [
            FlagRule::group(FlagRule::CMB_AND, [
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro', zone: FlagRule::ZONE_TRUSTED),
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'country', operator: FlagRule::OP_EQUALS, value: 'US', zone: FlagRule::ZONE_UNTRUSTED),
            ]),
        ]);

        $this->expectException(InvalidFlagException::class);
        $validator->validate($flag);
    }

    // --- variant weights ---

    public function test_variant_weights_over_100_rejected(): void
    {
        $validator = $this->validator([]);
        $flag      = new FeatureFlag(
            'id', 'exp', '', true, [], ['a' => 60, 'b' => 60],
            new \DateTimeImmutable(), new \DateTimeImmutable(),
        );

        $this->expectException(InvalidFlagException::class);
        $validator->validate($flag);
    }

    public function test_variant_weights_at_100_pass(): void
    {
        $validator = $this->validator([]);
        $flag      = new FeatureFlag(
            'id', 'exp', '', true, [], ['a' => 50, 'b' => 50],
            new \DateTimeImmutable(), new \DateTimeImmutable(),
        );

        $validator->validate($flag);
        $this->addToAssertionCount(1);
    }

    /** @param array<string,FeatureFlag> $stored */
    private function validator(array $stored): FlagValidator
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturnCallback(fn(string $n) => $stored[$n] ?? null);

        return new FlagValidator($storage);
    }

    /**
     * @param FlagRule[] $rules
     * @param Prerequisite[] $prerequisites
     */
    private function flag(string $name, FlagKind $kind = FlagKind::Release, array $rules = [], array $prerequisites = []): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id-' . $name, $name, '', true, $rules, null, $now, $now, kind: $kind, prerequisites: $prerequisites);
    }
}
