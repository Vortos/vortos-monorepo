<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Driver\S3\S3ClientFactory;
use Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore;
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
 */
final class R2ObjectStoreIntegrationTest extends TestCase
{
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

    public function test_real_r2_managed_lifecycle_rule_can_be_applied_and_removed(): void
    {
        if (getenv('OBJECT_STORE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Real object-store integration tests are disabled.');
        }

        $manager = new S3LifecycleManager(
            $this->client(),
            $this->requiredEnv('OBJECT_STORE_BUCKET'),
            ProviderCapabilities::forProvider(getenv('OBJECT_STORE_PROVIDER') ?: 'r2'),
            new NullLogger(),
            'tmp',
            86400,
            'vortos-object-store-integration-temp-expiry',
        );

        $applyPlan = $manager->planTemporaryUploadExpiry();
        $applied = $manager->apply($applyPlan);
        $this->assertTrue($applied->hasRule('vortos-object-store-integration-temp-expiry'));

        $removePlan = $manager->planRemoveManagedRule();
        $removed = $manager->apply($removePlan);
        $this->assertFalse($removed->hasRule('vortos-object-store-integration-temp-expiry'));
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
