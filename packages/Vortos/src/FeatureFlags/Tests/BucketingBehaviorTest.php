<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;

final class BucketingBehaviorTest extends TestCase
{
    private FlagEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
    }

    public function test_monotonic_ramp_keeps_prior_cohort(): void
    {
        // The defining property: raising a rollout % must never drop a user who was
        // already in. Verify across the whole population at increasing percentages.
        $contexts = [];
        for ($i = 0; $i < 2000; $i++) {
            $contexts[] = new FlagContext("user-{$i}");
        }

        $previousIn = [];
        foreach ([10, 25, 50, 75, 100] as $pct) {
            $flag = $this->pctFlag($pct);
            $in   = [];
            foreach ($contexts as $idx => $ctx) {
                if ($this->evaluator->evaluate($flag, $ctx)) {
                    $in[$idx] = true;
                }
            }

            foreach ($previousIn as $idx => $_) {
                $this->assertArrayHasKey($idx, $in, "user {$idx} dropped out when raising to {$pct}%");
            }
            $previousIn = $in;
        }
    }

    public function test_bucket_by_tenant_flips_whole_tenant_together(): void
    {
        $flag = $this->pctFlag(50, bucketBy: FeatureFlag::BUCKET_BY_TENANT);

        // Two different users in the same tenant must get the same decision.
        $tenantId = 'tenant-42';
        $userA    = new FlagContext('user-a', trusted: ['tenantId' => $tenantId]);
        $userB    = new FlagContext('user-b', trusted: ['tenantId' => $tenantId]);

        $this->assertSame(
            $this->evaluator->evaluate($flag, $userA),
            $this->evaluator->evaluate($flag, $userB),
            'same tenant must flip together when bucketBy=tenantId',
        );
    }

    public function test_bucket_by_tenant_ignores_user_identity(): void
    {
        $flag = $this->pctFlag(100, bucketBy: FeatureFlag::BUCKET_BY_TENANT);

        // No userId at all, but tenant present → still buckets (whole-company rollout).
        $ctx = new FlagContext(userId: null, trusted: ['tenantId' => 'acme']);
        $this->assertTrue($this->evaluator->evaluate($flag, $ctx));
    }

    public function test_missing_bucket_key_is_safe_no_match(): void
    {
        $flag = $this->pctFlag(100, bucketBy: FeatureFlag::BUCKET_BY_TENANT);

        // bucketBy=tenantId but no tenant in context → no match, not a crash.
        $this->assertFalse($this->evaluator->evaluate($flag, new FlagContext('user-x')));
    }

    public function test_tenant_bucketing_reads_only_trusted_zone(): void
    {
        $flag = $this->pctFlag(100, bucketBy: FeatureFlag::BUCKET_BY_TENANT);

        // A spoofed tenantId in the untrusted bag must NOT drive bucketing.
        $spoofed = new FlagContext('user-x', untrusted: ['tenantId' => 'acme']);
        $this->assertFalse($this->evaluator->evaluate($flag, $spoofed));

        $legit = new FlagContext('user-x', trusted: ['tenantId' => 'acme']);
        $this->assertTrue($this->evaluator->evaluate($flag, $legit));
    }

    public function test_device_bucketing_uses_untrusted_zone(): void
    {
        $flag = $this->pctFlag(100, bucketBy: FeatureFlag::BUCKET_BY_DEVICE);
        $ctx  = new FlagContext(userId: null, untrusted: ['deviceId' => 'device-123']);
        $this->assertTrue($this->evaluator->evaluate($flag, $ctx));
    }

    public function test_attribute_targeting_on_multi_context_fields(): void
    {
        // Target on a trusted plan + an untrusted country in one evaluation.
        $flag = $this->flag([
            FlagRule::group(FlagRule::CMB_AND, [
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'pro', zone: FlagRule::ZONE_TRUSTED),
                new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'country', operator: FlagRule::OP_IN, value: ['US', 'CA'], zone: FlagRule::ZONE_UNTRUSTED),
            ]),
        ]);

        $ctx = new FlagContext('u', trusted: ['plan' => 'pro'], untrusted: ['country' => 'CA']);
        $this->assertTrue($this->evaluator->evaluate($flag, $ctx));

        $wrongCountry = new FlagContext('u', trusted: ['plan' => 'pro'], untrusted: ['country' => 'DE']);
        $this->assertFalse($this->evaluator->evaluate($flag, $wrongCountry));
    }

    public function test_variant_assignment_is_stable_with_tenant_bucketing(): void
    {
        $flag = $this->flag([], variants: ['control' => 50, 'treatment' => 50], bucketBy: FeatureFlag::BUCKET_BY_TENANT);
        $a    = new FlagContext('user-a', trusted: ['tenantId' => 't1']);
        $b    = new FlagContext('user-b', trusted: ['tenantId' => 't1']);

        $this->assertSame(
            $this->evaluator->evaluateVariant($flag, $a),
            $this->evaluator->evaluateVariant($flag, $b),
        );
    }

    private function pctFlag(int $pct, string $bucketBy = FeatureFlag::BUCKET_BY_USER): FeatureFlag
    {
        return $this->flag([new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: $pct)], bucketBy: $bucketBy);
    }

    /**
     * @param FlagRule[] $rules
     */
    private function flag(array $rules, ?array $variants = null, string $bucketBy = FeatureFlag::BUCKET_BY_USER): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            'id', 'ramp-flag', '', true, $rules, $variants, $now, $now,
            bucketBy: $bucketBy,
        );
    }
}
