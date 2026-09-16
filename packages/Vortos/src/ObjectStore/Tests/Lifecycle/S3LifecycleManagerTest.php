<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Lifecycle;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Lifecycle\LifecyclePlanChange;
use Vortos\ObjectStore\Lifecycle\LifecycleRule;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;
use Vortos\ObjectStore\Lifecycle\S3LifecycleManager;

final class S3LifecycleManagerTest extends TestCase
{
    private const TMP_RULE = 'vortos-object-store-expire-temporary-uploads';

    public function test_plans_create_when_no_lifecycle_config_exists(): void
    {
        $handler = new MockHandler();
        $handler->append($this->noLifecycle());

        $plan = $this->manager($handler)->planManagedRules();

        $this->assertTrue($plan->hasChanges());
        $this->assertSame(LifecyclePlanChange::Create, $plan->change(self::TMP_RULE)?->change());
        $this->assertSame('tmp/', $plan->desired()->rule(self::TMP_RULE)['Filter']['Prefix']);
    }

    public function test_plans_update_and_preserves_unmanaged_rules(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['Rules' => [
            $this->rawRule('user-rule', 'archive/', expire: 365),
            $this->rawRule(self::TMP_RULE, 'old/', expire: 7),
        ]]));

        $plan = $this->manager($handler)->planManagedRules();

        $this->assertSame(LifecyclePlanChange::Update, $plan->change(self::TMP_RULE)?->change());
        $this->assertSame($this->rawRule('user-rule', 'archive/', expire: 365), $plan->desired()->rule('user-rule'));
        $this->assertNull($plan->change('user-rule'), 'unmanaged rules are not part of the change set');
        $this->assertSame('tmp/', $plan->desired()->rule(self::TMP_RULE)['Filter']['Prefix']);
    }

    public function test_declared_transition_rule_is_created_alongside_temporary_upload_expiry(): void
    {
        $handler = new MockHandler();
        $handler->append($this->noLifecycle());

        $plan = $this->manager($handler, [$this->iaRule(90)])->planManagedRules();

        $this->assertSame(LifecyclePlanChange::Create, $plan->change('vortos-app-submissions-ia')?->change());
        $this->assertSame(
            [['Days' => 90, 'StorageClass' => 'STANDARD_IA']],
            $plan->desired()->rule('vortos-app-submissions-ia')['Transitions'],
        );
        $this->assertCount(2, $plan->desired()->rules());
    }

    public function test_plan_converges_when_the_provider_echoes_rules_in_its_own_shape(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['Rules' => [
            // Legacy top-level Prefix and a different key order: same rules, not drift.
            ['Prefix' => 'tmp/', 'Expiration' => ['Days' => 1], 'Status' => 'Enabled', 'ID' => self::TMP_RULE],
            ['Transitions' => [['StorageClass' => 'STANDARD_IA', 'Days' => 90]], 'ID' => 'vortos-app-submissions-ia', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'submissions/']],
        ]]));

        $plan = $this->manager($handler, [$this->iaRule(90)])->planManagedRules();

        $this->assertFalse($plan->hasChanges());
    }

    public function test_changed_transition_day_is_an_update(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['Rules' => [
            LifecycleRule::temporaryUploadExpiry(self::TMP_RULE, 'tmp', 86400)->toS3Rule(),
            $this->iaRule(30)->toS3Rule(),
        ]]));

        $plan = $this->manager($handler, [$this->iaRule(90)])->planManagedRules();

        $this->assertSame(LifecyclePlanChange::None, $plan->change(self::TMP_RULE)?->change());
        $this->assertSame(LifecyclePlanChange::Update, $plan->change('vortos-app-submissions-ia')?->change());
    }

    public function test_managed_rule_removed_from_config_is_removed_from_the_bucket(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['Rules' => [
            LifecycleRule::temporaryUploadExpiry(self::TMP_RULE, 'tmp', 86400)->toS3Rule(),
            $this->iaRule(90)->toS3Rule(),
            $this->rawRule('Default Multipart Abort Rule', 'uploads/', expire: 7),
        ]]));

        $plan = $this->manager($handler)->planManagedRules();

        $this->assertSame(LifecyclePlanChange::Remove, $plan->change('vortos-app-submissions-ia')?->change());
        $this->assertFalse($plan->desired()->hasRule('vortos-app-submissions-ia'));
        $this->assertTrue($plan->desired()->hasRule('Default Multipart Abort Rule'));
    }

    public function test_temporary_upload_rule_is_left_alone_when_its_management_is_switched_off(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['Rules' => [$this->rawRule(self::TMP_RULE, 'tmp/', expire: 3)]]));

        $plan = $this->manager($handler, manageTemporaryUploads: false)->planManagedRules();

        $this->assertFalse($plan->hasChanges());
        $this->assertTrue($plan->desired()->hasRule(self::TMP_RULE));
    }

    public function test_provider_without_storage_class_transitions_is_refused_at_plan(): void
    {
        $handler = new MockHandler();

        $this->expectException(ObjectStoreConfigurationException::class);
        $this->manager($handler, [$this->iaRule(90)], provider: 'generic_s3')->planManagedRules();
    }

    public function test_aws_infrequent_access_floor_of_thirty_days_is_enforced_at_plan(): void
    {
        $handler = new MockHandler();

        $this->expectException(ObjectStoreConfigurationException::class);
        $this->expectExceptionMessage('requires at least 30');
        $this->manager($handler, [$this->iaRule(7)], provider: 'aws_s3')->planManagedRules();
    }

    public function test_r2_accepts_an_early_infrequent_access_transition(): void
    {
        $handler = new MockHandler();
        $handler->append($this->noLifecycle());

        $plan = $this->manager($handler, [$this->iaRule(7)], provider: 'r2')->planManagedRules();

        $this->assertSame(LifecyclePlanChange::Create, $plan->change('vortos-app-submissions-ia')?->change());
    }

    public function test_remove_plan_drops_every_managed_rule_and_keeps_the_rest(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['Rules' => [
            LifecycleRule::temporaryUploadExpiry(self::TMP_RULE, 'tmp', 86400)->toS3Rule(),
            $this->iaRule(90)->toS3Rule(),
            $this->rawRule('user-rule', 'archive/', expire: 365),
        ]]));

        $plan = $this->manager($handler, [$this->iaRule(90)])->planRemoveManagedRules();

        $this->assertSame(['user-rule'], array_column($plan->desired()->rules(), 'ID'));
        $this->assertCount(2, $plan->changes());
    }

    public function test_apply_writes_the_whole_desired_document(): void
    {
        $written = null;
        $handler = new MockHandler();
        $handler->append($this->noLifecycle());
        $handler->append(function (CommandInterface $command, RequestInterface $request) use (&$written): Result {
            $written = $command['LifecycleConfiguration'];
            return new Result([]);
        });

        $manager = $this->manager($handler, [$this->iaRule(90)]);
        $applied = $manager->apply($manager->planManagedRules());

        $this->assertSame($applied->toS3LifecycleConfiguration(), $written);
        $this->assertSame([self::TMP_RULE, 'vortos-app-submissions-ia'], array_column($written['Rules'], 'ID'));
    }

    public function test_apply_without_changes_does_not_write(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['Rules' => [LifecycleRule::temporaryUploadExpiry(self::TMP_RULE, 'tmp', 86400)->toS3Rule()]]));

        $manager = $this->manager($handler);
        $manager->apply($manager->planManagedRules());

        $this->assertSame(0, $handler->count(), 'no queued response consumed means no PutBucketLifecycleConfiguration call');
    }

    private function iaRule(int $days): LifecycleRule
    {
        return LifecycleRule::transitionAfter('vortos-app-submissions-ia', 'submissions', $days, ObjectStorageClass::InfrequentAccess);
    }

    /** @return array<string, mixed> */
    private function rawRule(string $id, string $prefix, int $expire): array
    {
        return ['ID' => $id, 'Status' => 'Enabled', 'Filter' => ['Prefix' => $prefix], 'Expiration' => ['Days' => $expire]];
    }

    private function noLifecycle(): AwsException
    {
        return new AwsException('missing', new \Aws\Command('GetBucketLifecycleConfiguration'), ['code' => 'NoSuchLifecycleConfiguration']);
    }

    /** @param list<LifecycleRule> $declared */
    private function manager(
        MockHandler $handler,
        array $declared = [],
        bool $manageTemporaryUploads = true,
        string $provider = 'r2',
    ): S3LifecycleManager {
        return new S3LifecycleManager(
            $this->client($handler),
            'media',
            ProviderCapabilities::forProvider($provider),
            new NullLogger(),
            'tmp',
            86400,
            self::TMP_RULE,
            manageTemporaryUploads: $manageTemporaryUploads,
            declaredRules: array_map(static fn(LifecycleRule $rule): array => $rule->toConfigArray(), $declared),
        );
    }

    private function client(MockHandler $handler): S3Client
    {
        return new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);
    }
}
