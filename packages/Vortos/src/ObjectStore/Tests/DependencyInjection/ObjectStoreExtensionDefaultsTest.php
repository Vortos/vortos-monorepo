<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ImmediateDirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectStoreRouterInterface;
use Vortos\ObjectStore\Contract\StandaloneDirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\StandaloneObjectStoreInterface;
use Vortos\ObjectStore\DependencyInjection\ObjectStoreExtension;
use Vortos\ObjectStore\DirectUpload\ImmediateDirectUploadManager;
use Vortos\ObjectStore\DirectUpload\StandaloneDirectUploadManager;
use Vortos\ObjectStore\DirectUpload\TransactionalOutboxDirectUploadManager;
use Vortos\ObjectStore\Driver\ImmediateObjectStore;
use Vortos\ObjectStore\Outbox\StandaloneObjectStore;
use Vortos\ObjectStore\Outbox\TransactionalOutboxObjectStore;
use Vortos\ObjectStore\Router\SingleObjectStoreRouter;

final class ObjectStoreExtensionDefaultsTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/object_store_no_config_' . uniqid());
        $this->container->setParameter('kernel.env', 'test');

        (new ObjectStoreExtension())->load([], $this->container);
    }

    public function test_alias_is_vortos_object_store(): void
    {
        $this->assertSame('vortos_object_store', (new ObjectStoreExtension())->getAlias());
    }

    public function test_driver_defaults_to_log(): void
    {
        $this->assertSame('log', $this->container->getParameter('vortos_object_store.driver'));
    }

    public function test_provider_defaults_to_r2(): void
    {
        $this->assertSame('r2', $this->container->getParameter('vortos_object_store.provider'));
    }

    public function test_region_defaults_to_auto_for_r2(): void
    {
        $this->assertSame('auto', $this->container->getParameter('vortos_object_store.region'));
    }

    public function test_endpoint_defaults_to_null(): void
    {
        $this->assertNull($this->container->getParameter('vortos_object_store.client.endpoint'));
        $this->assertNull($this->container->getParameter('vortos_object_store.client.account_id'));
    }

    public function test_bucket_name_defaults_to_empty_string(): void
    {
        $this->assertSame('', $this->container->getParameter('vortos_object_store.bucket.name'));
    }

    public function test_direct_upload_defaults_are_safe(): void
    {
        $this->assertSame(900, $this->container->getParameter('vortos_object_store.bucket.default_presign_ttl_seconds'));
        $this->assertSame(3600, $this->container->getParameter('vortos_object_store.bucket.max_presign_ttl_seconds'));
        $this->assertSame(5368709120, $this->container->getParameter('vortos_object_store.bucket.max_upload_size_bytes'));
        $this->assertSame('tmp', $this->container->getParameter('vortos_object_store.bucket.temporary_key_prefix'));
        $this->assertSame(86400, $this->container->getParameter('vortos_object_store.bucket.orphan_ttl_seconds'));
    }

    public function test_multipart_defaults(): void
    {
        $this->assertSame(104857600, $this->container->getParameter('vortos_object_store.multipart.threshold_bytes'));
        $this->assertSame(16777216, $this->container->getParameter('vortos_object_store.multipart.part_size_bytes'));
        $this->assertTrue($this->container->getParameter('vortos_object_store.multipart.abort_on_failure'));
        $this->assertSame(5497558138880, $this->container->getParameter('vortos_object_store.multipart.max_object_size_bytes'));
        $this->assertSame(16777216, $this->container->getParameter('vortos_object_store.multipart.max_inline_body_bytes'));
        $this->assertSame(3, $this->container->getParameter('vortos_object_store.multipart.max_attempts'));
        $this->assertSame(4, $this->container->getParameter('vortos_object_store.multipart.concurrency'));
        $this->assertSame(100, $this->container->getParameter('vortos_object_store.multipart.backoff_base_milliseconds'));
        $this->assertSame(2000, $this->container->getParameter('vortos_object_store.multipart.backoff_cap_milliseconds'));
        $this->assertNull($this->container->getParameter('vortos_object_store.multipart.checksum_algorithm'));
    }

    public function test_outbox_defaults_to_enabled(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_object_store.outbox.enabled'));
        $this->assertSame('vortos_object_store_outbox', $this->container->getParameter('vortos_object_store.outbox.table_name'));
        $this->assertSame(1048576, $this->container->getParameter('vortos_object_store.outbox.max_inline_payload_bytes'));
    }

    public function test_lifecycle_defaults_are_safe_for_expiring_temporary_uploads(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_object_store.lifecycle.enabled'));
        $this->assertTrue($this->container->getParameter('vortos_object_store.lifecycle.manage_temporary_uploads'));
        $this->assertSame('vortos-object-store-expire-temporary-uploads', $this->container->getParameter('vortos_object_store.lifecycle.rule_id'));
        $this->assertTrue($this->container->getParameter('vortos_object_store.lifecycle.require_confirmation'));
        $this->assertFalse($this->container->getParameter('vortos_object_store.lifecycle.round_up_minimum_lifecycle_day'));
    }

    public function test_observability_defaults_to_enabled_and_can_use_noop_framework_services(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_object_store.observability.logging'));
        $this->assertTrue($this->container->getParameter('vortos_object_store.observability.tracing'));
        $this->assertTrue($this->container->getParameter('vortos_object_store.observability.metrics'));
        $this->assertSame([], $this->container->getParameter('vortos_object_store.observability.logging_disabled_for'));
        $this->assertSame([], $this->container->getParameter('vortos_object_store.observability.tracing_disabled_for'));
        $this->assertSame([], $this->container->getParameter('vortos_object_store.observability.metrics_disabled_for'));
    }

    public function test_policy_public_url_and_diagnostic_services_are_registered(): void
    {
        $this->assertTrue($this->container->hasDefinition(\Vortos\ObjectStore\Middleware\PresignedUrlPolicyMiddleware::class));
        $this->assertTrue($this->container->hasDefinition(\Vortos\ObjectStore\Url\PublicUrlBuilder::class));
        $this->assertTrue($this->container->hasAlias(\Vortos\ObjectStore\Contract\PublicUrlGeneratorInterface::class));
        $this->assertTrue($this->container->hasDefinition(\Vortos\ObjectStore\Command\ObjectStoreHeadCommand::class));
        $this->assertTrue($this->container->hasDefinition(\Vortos\ObjectStore\Command\ObjectStorePresignCommand::class));
        $this->assertTrue($this->container->hasDefinition(\Vortos\ObjectStore\Command\ObjectStoreLifecycleCommand::class));
    }

    public function test_service_aliases_express_delivery_guarantees(): void
    {
        $this->assertSame(TransactionalOutboxObjectStore::class, (string) $this->container->getAlias(ObjectStoreInterface::class));
        $this->assertSame(TransactionalOutboxDirectUploadManager::class, (string) $this->container->getAlias(DirectUploadManagerInterface::class));

        $this->assertSame(StandaloneObjectStore::class, (string) $this->container->getAlias(StandaloneObjectStoreInterface::class));
        $this->assertSame(StandaloneDirectUploadManager::class, (string) $this->container->getAlias(StandaloneDirectUploadManagerInterface::class));

        $this->assertSame(ImmediateObjectStore::class, (string) $this->container->getAlias(ImmediateObjectStoreInterface::class));
        $this->assertSame(ImmediateDirectUploadManager::class, (string) $this->container->getAlias(ImmediateDirectUploadManagerInterface::class));
        $this->assertSame(SingleObjectStoreRouter::class, (string) $this->container->getAlias(ObjectStoreRouterInterface::class));
    }

    public function test_diagnostic_commands_use_immediate_provider_path(): void
    {
        $headStore = $this->container
            ->getDefinition(\Vortos\ObjectStore\Command\ObjectStoreHeadCommand::class)
            ->getArgument('$objectStore');
        $presignStore = $this->container
            ->getDefinition(\Vortos\ObjectStore\Command\ObjectStorePresignCommand::class)
            ->getArgument('$objectStore');

        $this->assertInstanceOf(Reference::class, $headStore);
        $this->assertInstanceOf(Reference::class, $presignStore);
        $this->assertSame(ImmediateObjectStoreInterface::class, (string) $headStore);
        $this->assertSame(ImmediateObjectStoreInterface::class, (string) $presignStore);
    }
}
