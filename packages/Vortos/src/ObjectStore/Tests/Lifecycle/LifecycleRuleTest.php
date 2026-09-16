<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Lifecycle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Lifecycle\LifecycleRule;
use Vortos\ObjectStore\Lifecycle\LifecycleRuleStatus;
use Vortos\ObjectStore\Lifecycle\LifecycleTransition;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;

final class LifecycleRuleTest extends TestCase
{
    public function test_temporary_upload_rule_uses_normalized_prefix_and_day_expiration(): void
    {
        $rule = LifecycleRule::temporaryUploadExpiry('managed', '/tmp/', 90000);

        $this->assertSame([
            'ID' => 'managed',
            'Status' => 'Enabled',
            'Filter' => ['Prefix' => 'tmp/'],
            'Expiration' => ['Days' => 2],
        ], $rule->toS3Rule());
    }

    public function test_rejects_sub_day_ttl_by_default(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 3600);
    }

    public function test_can_round_sub_day_ttl_up_when_explicitly_enabled(): void
    {
        $this->assertSame(1, LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 3600, true)->expirationDays());
    }

    public function test_transition_rule_emits_s3_transitions_without_expiration(): void
    {
        $rule = LifecycleRule::transitionAfter('vortos-ia', 'submissions', 90, ObjectStorageClass::InfrequentAccess);

        $this->assertSame([
            'ID' => 'vortos-ia',
            'Status' => 'Enabled',
            'Filter' => ['Prefix' => 'submissions/'],
            'Transitions' => [['Days' => 90, 'StorageClass' => 'STANDARD_IA']],
        ], $rule->toS3Rule());
    }

    public function test_rule_can_transition_then_expire(): void
    {
        $rule = new LifecycleRule('vortos-both', 'exports', 365, [new LifecycleTransition(30, ObjectStorageClass::InfrequentAccess)]);

        $this->assertSame(365, $rule->toS3Rule()['Expiration']['Days']);
        $this->assertSame(30, $rule->toS3Rule()['Transitions'][0]['Days']);
    }

    public function test_rejects_rule_without_any_action(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        new LifecycleRule('vortos-nothing', 'uploads');
    }

    public function test_rejects_empty_prefix_so_a_rule_cannot_sweep_the_whole_bucket(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        LifecycleRule::transitionAfter('vortos-all', '/', 30, ObjectStorageClass::InfrequentAccess);
    }

    public function test_rejects_transition_that_expiration_would_pre_empt(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        $this->expectExceptionMessage('would never take effect');
        new LifecycleRule('vortos-late', 'exports', 30, [new LifecycleTransition(30, ObjectStorageClass::InfrequentAccess)]);
    }

    public function test_rejects_two_transitions_to_the_same_class(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        new LifecycleRule('vortos-dup', 'exports', null, [
            new LifecycleTransition(30, ObjectStorageClass::InfrequentAccess),
            new LifecycleTransition(60, ObjectStorageClass::InfrequentAccess),
        ]);
    }

    public function test_rejects_transition_before_day_one(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        new LifecycleTransition(0, ObjectStorageClass::InfrequentAccess);
    }

    public function test_rejects_over_long_rule_id(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        LifecycleRule::expireAfter(str_repeat('a', 256), 'tmp', 1);
    }

    public function test_parses_rule_as_the_provider_returns_it_and_compares_as_a_model(): void
    {
        $declared = LifecycleRule::transitionAfter('vortos-ia', 'submissions/', 90, ObjectStorageClass::InfrequentAccess);

        // Provider echo: different key order, legacy top-level Prefix instead of Filter.
        $parsed = LifecycleRule::fromS3Rule([
            'Transitions' => [['StorageClass' => 'STANDARD_IA', 'Days' => 90]],
            'Prefix' => 'submissions/',
            'Status' => 'Enabled',
            'ID' => 'vortos-ia',
        ]);

        $this->assertNotNull($parsed);
        $this->assertTrue($declared->equals($parsed));
    }

    public function test_equality_detects_a_changed_transition_day(): void
    {
        $a = LifecycleRule::transitionAfter('vortos-ia', 'submissions', 90, ObjectStorageClass::InfrequentAccess);
        $b = LifecycleRule::transitionAfter('vortos-ia', 'submissions', 60, ObjectStorageClass::InfrequentAccess);

        $this->assertFalse($a->equals($b));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unrepresentableRules(): iterable
    {
        yield 'date expiration' => [['ID' => 'x', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'a/'], 'Expiration' => ['Date' => '2030-01-01T00:00:00Z']]];
        yield 'tag filter' => [['ID' => 'x', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'a/', 'Tag' => ['Key' => 'k', 'Value' => 'v']], 'Expiration' => ['Days' => 3]]];
        yield 'archive class' => [['ID' => 'x', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'a/'], 'Transitions' => [['Days' => 3, 'StorageClass' => 'GLACIER']]]];
        yield 'multipart abort' => [['ID' => 'x', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'a/'], 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7]]];
        yield 'bucket-wide' => [['ID' => 'x', 'Status' => 'Enabled', 'Filter' => ['Prefix' => ''], 'Expiration' => ['Days' => 3]]];
        yield 'unknown status' => [['ID' => 'x', 'Status' => 'Paused', 'Filter' => ['Prefix' => 'a/'], 'Expiration' => ['Days' => 3]]];
    }

    /** @param array<string, mixed> $rule */
    #[DataProvider('unrepresentableRules')]
    public function test_rules_the_model_cannot_represent_exactly_parse_to_null(array $rule): void
    {
        $this->assertNull(LifecycleRule::fromS3Rule($rule));
    }

    public function test_config_array_round_trips(): void
    {
        $rule = new LifecycleRule(
            'vortos-both',
            'exports',
            365,
            [new LifecycleTransition(30, ObjectStorageClass::InfrequentAccess)],
            LifecycleRuleStatus::Disabled,
        );

        $this->assertTrue($rule->equals(LifecycleRule::fromConfigArray($rule->toConfigArray())));
    }

    public function test_config_array_with_unknown_storage_class_is_refused(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        LifecycleRule::fromConfigArray([
            'id' => 'vortos-x',
            'prefix' => 'a',
            'transitions' => [['days' => 30, 'storage_class' => 'GLACIER']],
        ]);
    }
}
