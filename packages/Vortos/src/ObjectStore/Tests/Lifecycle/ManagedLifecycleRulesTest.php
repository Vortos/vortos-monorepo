<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Lifecycle\LifecycleRule;
use Vortos\ObjectStore\Lifecycle\ManagedLifecycleRules;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;

final class ManagedLifecycleRulesTest extends TestCase
{
    public function test_owns_rules_by_id_namespace_including_undeclared_ones(): void
    {
        $managed = new ManagedLifecycleRules('vortos-', [LifecycleRule::expireAfter('vortos-tmp', 'tmp', 1)]);

        $this->assertTrue($managed->owns('vortos-tmp'));
        $this->assertTrue($managed->owns('vortos-removed-from-config'));
        $this->assertFalse($managed->owns('Default Multipart Abort Rule'));
        $this->assertNull($managed->rule('vortos-removed-from-config'));
    }

    public function test_refuses_a_declared_rule_outside_the_namespace(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        $this->expectExceptionMessage('managed prefix');
        new ManagedLifecycleRules('vortos-', [LifecycleRule::expireAfter('console-rule', 'tmp', 1)]);
    }

    public function test_refuses_an_empty_namespace_which_would_claim_every_rule(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        new ManagedLifecycleRules(' ', []);
    }

    public function test_refuses_duplicate_ids(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        new ManagedLifecycleRules('vortos-', [
            LifecycleRule::expireAfter('vortos-a', 'tmp', 1),
            LifecycleRule::expireAfter('vortos-a', 'other', 2),
        ]);
    }

    public function test_refuses_more_rules_than_a_bucket_accepts(): void
    {
        $rules = [];
        for ($i = 0; $i <= ManagedLifecycleRules::MAX_RULES_PER_BUCKET; $i++) {
            $rules[] = LifecycleRule::expireAfter('vortos-' . $i, 'p' . $i, 1);
        }

        $this->expectException(ObjectStoreConfigurationException::class);
        new ManagedLifecycleRules('vortos-', $rules);
    }

    public function test_reports_whether_any_rule_transitions(): void
    {
        $this->assertFalse((new ManagedLifecycleRules('vortos-', [LifecycleRule::expireAfter('vortos-a', 'tmp', 1)]))->hasTransitions());
        $this->assertTrue((new ManagedLifecycleRules('vortos-', [
            LifecycleRule::transitionAfter('vortos-b', 'uploads', 30, ObjectStorageClass::InfrequentAccess),
        ]))->hasTransitions());
    }
}
