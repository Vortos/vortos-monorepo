<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\PresignedUrlGeneratorInterface;

final class ObjectStoreInterfaceTest extends TestCase
{
    public function test_object_store_exposes_presigned_url_generation(): void
    {
        $this->assertContains(PresignedUrlGeneratorInterface::class, class_implements(ObjectStoreInterface::class));
        $this->assertTrue(method_exists(ObjectStoreInterface::class, 'temporaryUploadUrl'));
        $this->assertTrue(method_exists(ObjectStoreInterface::class, 'temporaryPostUpload'));
        $this->assertTrue(method_exists(ObjectStoreInterface::class, 'temporaryDownloadUrl'));
    }

    public function test_direct_upload_manager_exposes_orphan_safe_lifecycle(): void
    {
        $this->assertTrue(method_exists(DirectUploadManagerInterface::class, 'createUploadIntent'));
        $this->assertTrue(method_exists(DirectUploadManagerInterface::class, 'promote'));
        $this->assertTrue(method_exists(DirectUploadManagerInterface::class, 'abort'));
    }
}
