<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle\StateBackend;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\StateBackend\StateBackendProvider;

final class StateBackendProviderTest extends TestCase
{
    public function test_s3_is_remote(): void
    {
        $this->assertTrue(StateBackendProvider::S3Dynamodb->isRemote());
        $this->assertTrue(StateBackendProvider::S3Dynamodb->supportsLocking());
    }

    public function test_gcs_is_remote(): void
    {
        $this->assertTrue(StateBackendProvider::Gcs->isRemote());
        $this->assertTrue(StateBackendProvider::Gcs->supportsLocking());
    }

    public function test_local_is_not_remote(): void
    {
        $this->assertFalse(StateBackendProvider::Local->isRemote());
        $this->assertFalse(StateBackendProvider::Local->supportsLocking());
    }
}
