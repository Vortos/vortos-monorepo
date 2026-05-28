<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\DependencyInjection\ObjectStoreExtension;

final class ObjectStoreExtensionConfigOverrideTest extends TestCase
{
    public function test_loads_project_config_file(): void
    {
        $projectDir = sys_get_temp_dir() . '/object_store_config_' . uniqid();
        mkdir($projectDir . '/config', 0777, true);

        file_put_contents($projectDir . '/config/object_store.php', <<<'PHP'
<?php
use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;

return static function (VortosObjectStoreConfig $config): void {
    $config
        ->driver('null')
        ->provider('generic_s3')
        ->region('us-east-1')
        ->endpoint('https://storage.example.test')
        ->bucket('media');

    $config->bucketConfig()
        ->keyPrefix('tenant-a')
        ->temporaryKeyPrefix('pending')
        ->orphanTtlSeconds(3600)
        ->maxUploadSizeBytes(209715200)
        ->defaultPresignTtlSeconds(600)
        ->maxPresignTtlSeconds(1800);
};
PHP);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.env', 'test');

        (new ObjectStoreExtension())->load([], $container);

        $this->assertSame('null', $container->getParameter('vortos_object_store.driver'));
        $this->assertSame('generic_s3', $container->getParameter('vortos_object_store.provider'));
        $this->assertSame('us-east-1', $container->getParameter('vortos_object_store.region'));
        $this->assertSame('https://storage.example.test', $container->getParameter('vortos_object_store.client.endpoint'));
        $this->assertSame('media', $container->getParameter('vortos_object_store.bucket.name'));
        $this->assertSame('tenant-a', $container->getParameter('vortos_object_store.bucket.key_prefix'));
        $this->assertSame('pending', $container->getParameter('vortos_object_store.bucket.temporary_key_prefix'));
        $this->assertSame(3600, $container->getParameter('vortos_object_store.bucket.orphan_ttl_seconds'));
        $this->assertSame(209715200, $container->getParameter('vortos_object_store.bucket.max_upload_size_bytes'));
        $this->assertSame(600, $container->getParameter('vortos_object_store.bucket.default_presign_ttl_seconds'));
        $this->assertSame(1800, $container->getParameter('vortos_object_store.bucket.max_presign_ttl_seconds'));
    }

    public function test_s3_driver_registers_s3_object_store(): void
    {
        $projectDir = sys_get_temp_dir() . '/object_store_s3_config_' . uniqid();
        mkdir($projectDir . '/config', 0777, true);

        file_put_contents($projectDir . '/config/object_store.php', <<<'PHP'
<?php
use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;

return static function (VortosObjectStoreConfig $config): void {
    $config
        ->driver('s3')
        ->provider('r2')
        ->region('auto')
        ->bucket('media');

    $config->client()
        ->accountId('abc123')
        ->credentials('key', 'secret');
};
PHP);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.env', 'test');

        (new ObjectStoreExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition(\Aws\S3\S3Client::class));
        $this->assertTrue($container->hasDefinition(\Vortos\ObjectStore\Driver\S3\S3CompatibleObjectStore::class));
        $this->assertTrue($container->hasDefinition(\Vortos\ObjectStore\Command\ObjectStoreMultipartCommand::class));
        $this->assertSame('s3', $container->getParameter('vortos_object_store.driver'));
        $this->assertSame('abc123', $container->getParameter('vortos_object_store.client.account_id'));
    }

    public function test_observability_can_be_opted_out(): void
    {
        $projectDir = sys_get_temp_dir() . '/object_store_observability_config_' . uniqid();
        mkdir($projectDir . '/config', 0777, true);

        file_put_contents($projectDir . '/config/object_store.php', <<<'PHP'
<?php
use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;

return static function (VortosObjectStoreConfig $config): void {
    $config->observability()->logging(false)->tracing(false)->metrics(false);
    $config->outbox()->enabled(false);
};
PHP);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.env', 'test');

        (new ObjectStoreExtension())->load([], $container);

        $this->assertFalse($container->getParameter('vortos_object_store.observability.logging'));
        $this->assertFalse($container->getParameter('vortos_object_store.observability.tracing'));
        $this->assertFalse($container->getParameter('vortos_object_store.observability.metrics'));
        $this->assertFalse($container->hasDefinition(\Vortos\ObjectStore\Middleware\LoggingMiddleware::class));
        $this->assertFalse($container->hasDefinition(\Vortos\ObjectStore\Middleware\TracingMiddleware::class));
        $this->assertFalse($container->hasDefinition(\Vortos\ObjectStore\Middleware\MetricsMiddleware::class));
    }

    public function test_observability_can_be_opted_out_by_typed_section(): void
    {
        $projectDir = sys_get_temp_dir() . '/object_store_observability_sections_' . uniqid();
        mkdir($projectDir . '/config', 0777, true);

        file_put_contents($projectDir . '/config/object_store.php', <<<'PHP'
<?php
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;

return static function (VortosObjectStoreConfig $config): void {
    $config->observability()
        ->disableLoggingFor(ObjectStoreObservabilitySection::Presign)
        ->disableTracingFor(ObjectStoreObservabilitySection::DirectUpload)
        ->disableMetricsFor(ObjectStoreObservabilitySection::Outbox);
    $config->outbox()->enabled(false);
};
PHP);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.env', 'test');

        (new ObjectStoreExtension())->load([], $container);

        $this->assertSame([ObjectStoreObservabilitySection::Presign->value], $container->getParameter('vortos_object_store.observability.logging_disabled_for'));
        $this->assertSame([ObjectStoreObservabilitySection::DirectUpload->value], $container->getParameter('vortos_object_store.observability.tracing_disabled_for'));
        $this->assertSame([ObjectStoreObservabilitySection::Outbox->value], $container->getParameter('vortos_object_store.observability.metrics_disabled_for'));
    }

    public function test_lifecycle_config_can_be_overridden(): void
    {
        $projectDir = sys_get_temp_dir() . '/object_store_lifecycle_config_' . uniqid();
        mkdir($projectDir . '/config', 0777, true);

        file_put_contents($projectDir . '/config/object_store.php', <<<'PHP'
<?php
use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;

return static function (VortosObjectStoreConfig $config): void {
    $config->lifecycle()
        ->enabled(false)
        ->manageTemporaryUploads(false)
        ->ruleId('custom-managed-rule')
        ->requireConfirmation(false)
        ->roundUpMinimumLifecycleDay(true);
    $config->outbox()->enabled(false);
};
PHP);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.env', 'test');

        (new ObjectStoreExtension())->load([], $container);

        $this->assertFalse($container->getParameter('vortos_object_store.lifecycle.enabled'));
        $this->assertFalse($container->getParameter('vortos_object_store.lifecycle.manage_temporary_uploads'));
        $this->assertSame('custom-managed-rule', $container->getParameter('vortos_object_store.lifecycle.rule_id'));
        $this->assertFalse($container->getParameter('vortos_object_store.lifecycle.require_confirmation'));
        $this->assertTrue($container->getParameter('vortos_object_store.lifecycle.round_up_minimum_lifecycle_day'));
    }
}
