<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Lifecycle;

use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Lifecycle\LifecyclePlanChange;
use Vortos\ObjectStore\Lifecycle\S3LifecycleManager;

final class S3LifecycleManagerTest extends TestCase
{
    public function test_plans_create_when_no_lifecycle_config_exists(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException('missing', new \Aws\Command('GetBucketLifecycleConfiguration'), ['code' => 'NoSuchLifecycleConfiguration']));

        $plan = $this->manager($handler)->planTemporaryUploadExpiry();

        $this->assertSame(LifecyclePlanChange::Create, $plan->change());
        $this->assertSame('tmp/', $plan->desired()->rule('managed')['Filter']['Prefix']);
    }

    public function test_plans_update_and_preserves_unmanaged_rules(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'Rules' => [
                [
                    'ID' => 'user-rule',
                    'Status' => 'Enabled',
                    'Filter' => ['Prefix' => 'archive/'],
                    'Expiration' => ['Days' => 365],
                ],
                [
                    'ID' => 'managed',
                    'Status' => 'Enabled',
                    'Filter' => ['Prefix' => 'old/'],
                    'Expiration' => ['Days' => 7],
                ],
            ],
        ]));

        $plan = $this->manager($handler)->planTemporaryUploadExpiry();

        $this->assertSame(LifecyclePlanChange::Update, $plan->change());
        $this->assertTrue($plan->desired()->hasRule('user-rule'));
        $this->assertSame('tmp/', $plan->desired()->rule('managed')['Filter']['Prefix']);
    }

    public function test_apply_returns_desired_configuration_when_write_succeeds(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([]));
        $handler->append(new Result([]));

        $manager = $this->manager($handler);
        $plan = $manager->planTemporaryUploadExpiry();
        $applied = $manager->apply($plan);

        $this->assertTrue($applied->hasRule('managed'));
    }

    private function manager(MockHandler $handler): S3LifecycleManager
    {
        return new S3LifecycleManager(
            $this->client($handler),
            'media',
            ProviderCapabilities::forProvider('r2'),
            new NullLogger(),
            'tmp',
            86400,
            'managed',
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
