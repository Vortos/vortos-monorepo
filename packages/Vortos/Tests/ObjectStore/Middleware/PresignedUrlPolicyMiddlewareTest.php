<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;
use Vortos\ObjectStore\Exception\PresignedUrlPolicyException;
use Vortos\ObjectStore\Middleware\PresignedUrlPolicyMiddleware;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;
use Vortos\ObjectStore\ValueObject\UploadConstraints;

final class PresignedUrlPolicyMiddlewareTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function test_rejects_presigned_download_ttl_above_configured_maximum(): void
    {
        $middleware = new PresignedUrlPolicyMiddleware(60, 1000, $this->now);
        $operation = new ObjectStoreOperation(ObjectStoreOperationName::TemporaryDownloadUrl, [
            'expires_at' => $this->now->modify('+61 seconds'),
        ]);

        $this->expectException(PresignedUrlPolicyException::class);
        $middleware->process($operation, static fn() => null);
    }

    public function test_rejects_direct_upload_size_above_configured_maximum(): void
    {
        $middleware = new PresignedUrlPolicyMiddleware(60, 1000, $this->now);
        $operation = new ObjectStoreOperation(ObjectStoreOperationName::TemporaryUploadUrl, [
            'options' => new TemporaryUploadUrlOptions(
                $this->now->modify('+30 seconds'),
                UploadConstraints::forDirectUpload('video/mp4', 1001),
            ),
        ]);

        $this->expectException(PresignedUrlPolicyException::class);
        $middleware->process($operation, static fn() => null);
    }

    public function test_allows_compliant_direct_upload_policy(): void
    {
        $middleware = new PresignedUrlPolicyMiddleware(60, 1000, $this->now);
        $operation = new ObjectStoreOperation(ObjectStoreOperationName::TemporaryUploadUrl, [
            'options' => new TemporaryUploadUrlOptions(
                $this->now->modify('+30 seconds'),
                UploadConstraints::forDirectUpload('video/mp4', 1000),
            ),
        ]);

        $this->assertSame('ok', $middleware->process($operation, static fn() => 'ok'));
    }
}
