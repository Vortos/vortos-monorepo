<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runner\DeployExecutionMode;
use Vortos\Deploy\Runner\DeployRequest;

final class ImageDigestPassthroughTest extends TestCase
{
    public function test_image_digest_accepted_when_valid(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $request = new DeployRequest('production', imageDigest: $digest);

        $this->assertSame($digest, $request->imageDigest);
    }

    public function test_image_digest_null_by_default(): void
    {
        $request = new DeployRequest('production');

        $this->assertNull($request->imageDigest);
    }

    public function test_image_digest_invalid_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image digest must match');

        new DeployRequest('production', imageDigest: 'not-valid');
    }

    public function test_image_digest_short_hash_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeployRequest('production', imageDigest: 'sha256:abc123');
    }

    public function test_dry_run_with_image_digest(): void
    {
        $digest = 'sha256:' . str_repeat('b', 64);
        $request = new DeployRequest('staging', DeployExecutionMode::DryRun, imageDigest: $digest);

        $this->assertTrue($request->isDryRun());
        $this->assertSame($digest, $request->imageDigest);
    }

    public function test_existing_static_factories_unchanged(): void
    {
        $dryRun = DeployRequest::dryRun('staging');
        $this->assertTrue($dryRun->isDryRun());
        $this->assertNull($dryRun->imageDigest);

        $live = DeployRequest::live('production', true, false);
        $this->assertFalse($live->isDryRun());
        $this->assertNull($live->imageDigest);
    }
}
