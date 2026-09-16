<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Driver\S3\S3ClientFactory;
use Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore;
use Vortos\ObjectStore\Lifecycle\LifecyclePlanChange;
use Vortos\ObjectStore\Lifecycle\LifecycleRule;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;
use Vortos\ObjectStore\Lifecycle\S3LifecycleManager;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

/**
 * Real-provider integration coverage only.
 *
 * Enable in CI with:
 *   OBJECT_STORE_INTEGRATION=1
 *   OBJECT_STORE_PROVIDER=r2
 *   OBJECT_STORE_ACCOUNT_ID=...
 *   OBJECT_STORE_ACCESS_KEY_ID=...
 *   OBJECT_STORE_SECRET_ACCESS_KEY=...
 *   OBJECT_STORE_BUCKET=...
 *
 * Point it at a non-production bucket: the lifecycle test writes the bucket's lifecycle document.
 */
final class R2ObjectStoreIntegrationTest extends TestCase
{
    /** A prefix no application writes to, so a probe rule that outlives a failed run acts on nothing. */
    private const PROBE_PREFIX = 'vortos-lifecycle-probe';

    public function test_real_r2_object_lifecycle_and_presign_flow(): void
    {
        if (getenv('OBJECT_STORE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Real object-store integration tests are disabled.');
        }

        $bucket = $this->requiredEnv('OBJECT_STORE_BUCKET');
        $store = new S3CompatibleObjectStore($this->client(), $bucket, getenv('OBJECT_STORE_PROVIDER') ?: 'r2');
        $key = 'tmp/vortos-integration-' . bin2hex(random_bytes(8)) . '.txt';

        try {
            $stored = $store->put($key, 'integration-ok', PutObjectOptions::default());
            $this->assertSame($key, $stored->key()->value());
            $this->assertTrue($store->exists($key));
            $this->assertSame('integration-ok', $store->get($key)->contents());

            $downloadUrl = $store->temporaryDownloadUrl($key, (new \DateTimeImmutable())->modify('+5 minutes'));
            $uploadUrl = $store->temporaryUploadUrl($key, TemporaryUploadUrlOptions::forDirectUpload(300, 'text/plain', 1024));

            $this->assertStringStartsWith('http', $downloadUrl->url());
            $this->assertStringStartsWith('http', $uploadUrl->url()->url());
        } finally {
            $store->delete($key);
        }
    }

    /**
     * Proves the plan converges against the provider's real echo of a rule. A unit test can only
     * feed the parser shapes someone wrote down; if the provider returns a rule in a shape
     * fromS3Rule() does not recognise, the second plan reports Update forever and every deploy
     * would rewrite the bucket's lifecycle.
     */
    public function test_real_managed_lifecycle_rules_converge_update_and_are_removed_without_touching_other_rules(): void
    {
        if (getenv('OBJECT_STORE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Real object-store integration tests are disabled.');
        }

        // A namespace unique to this run: planRemoveManagedRules() removes every rule in the
        // namespace, so sharing the default "vortos-" would delete the bucket's real managed rules.
        $namespace = 'vortos-it-' . bin2hex(random_bytes(4)) . '-';
        $ruleId = $namespace . 'probe-ia';

        $before = $this->lifecycleManager($namespace, [])->current();

        try {
            $declared = $this->lifecycleManager($namespace, [
                LifecycleRule::transitionAfter($ruleId, self::PROBE_PREFIX, 30, ObjectStorageClass::InfrequentAccess),
            ]);

            $create = $declared->planManagedRules();
            $this->assertSame(LifecyclePlanChange::Create, $create->change($ruleId)?->change());
            $applied = $declared->apply($create);
            $this->assertTrue($applied->hasRule($ruleId));

            $converged = $declared->planManagedRules();
            $this->assertFalse(
                $converged->hasChanges(),
                'Provider echoed the rule in a shape the model does not parse: ' . json_encode($converged->toArray()),
            );
            $this->assertOtherRulesUntouched($before->rules(), $declared->current()->rules(), $namespace);

            $redeclared = $this->lifecycleManager($namespace, [
                LifecycleRule::transitionAfter($ruleId, self::PROBE_PREFIX, 60, ObjectStorageClass::InfrequentAccess),
            ]);
            $update = $redeclared->planManagedRules();
            $this->assertSame(LifecyclePlanChange::Update, $update->change($ruleId)?->change());
            $redeclared->apply($update);
            $this->assertFalse($redeclared->planManagedRules()->hasChanges());

            $undeclared = $this->lifecycleManager($namespace, []);
            $remove = $undeclared->planManagedRules();
            $this->assertSame(LifecyclePlanChange::Remove, $remove->change($ruleId)?->change());
            $removed = $undeclared->apply($remove);
            $this->assertFalse($removed->hasRule($ruleId));
            $this->assertOtherRulesUntouched($before->rules(), $undeclared->current()->rules(), $namespace);
        } finally {
            $cleanup = $this->lifecycleManager($namespace, []);
            $plan = $cleanup->planRemoveManagedRules();
            if ($plan->hasChanges()) {
                $cleanup->apply($plan);
            }
        }
    }

    /** @param list<LifecycleRule> $declaredRules */
    private function lifecycleManager(string $namespace, array $declaredRules): S3LifecycleManager
    {
        return new S3LifecycleManager(
            $this->client(),
            $this->requiredEnv('OBJECT_STORE_BUCKET'),
            ProviderCapabilities::forProvider(getenv('OBJECT_STORE_PROVIDER') ?: 'r2'),
            new NullLogger(),
            'tmp',
            86400,
            $namespace . 'temp-expiry',
            manageTemporaryUploads: false,
            declaredRules: array_map(static fn(LifecycleRule $rule): array => $rule->toConfigArray(), $declaredRules),
            managedRuleIdPrefix: $namespace,
        );
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    private function assertOtherRulesUntouched(array $before, array $after, string $namespace): void
    {
        $others = static fn(array $rules): array => array_values(array_filter(
            array_map(static fn(array $rule): string => (string) json_encode($rule), $rules),
            static fn(string $encoded): bool => !str_contains($encoded, '"' . $namespace),
        ));

        $expected = $others($before);
        $actual = $others($after);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, 'Rules outside the managed namespace changed.');
    }

    private function client(): \Aws\S3\S3Client
    {
        return S3ClientFactory::create(
            getenv('OBJECT_STORE_PROVIDER') ?: 'r2',
            getenv('OBJECT_STORE_REGION') ?: 'auto',
            getenv('OBJECT_STORE_ENDPOINT') !== false ? getenv('OBJECT_STORE_ENDPOINT') : null,
            getenv('OBJECT_STORE_ACCOUNT_ID') !== false ? getenv('OBJECT_STORE_ACCOUNT_ID') : null,
            $this->requiredEnv('OBJECT_STORE_ACCESS_KEY_ID'),
            $this->requiredEnv('OBJECT_STORE_SECRET_ACCESS_KEY'),
            10.0,
            2.0,
            3,
            false,
        );
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $this->markTestSkipped(sprintf('Missing required env var %s.', $name));
        }

        return $value;
    }
}
