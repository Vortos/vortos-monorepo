<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\InvalidUploadConstraintException;
use Vortos\ObjectStore\ValueObject\UploadConstraints;

final class UploadConstraintsTest extends TestCase
{
    public function test_direct_upload_constraints_include_exact_content_type_header(): void
    {
        $constraints = UploadConstraints::forDirectUpload('video/mp4', 209715200);

        $this->assertSame('video/mp4', $constraints->requiredHeaders()['Content-Type']);
        $this->assertSame(209715200, $constraints->maxSizeBytes());
    }

    public function test_post_policy_content_length_range_is_explicit(): void
    {
        $constraints = UploadConstraints::forDirectUpload('video/mp4', 209715200, 1);

        $this->assertSame(['content-length-range', 1, 209715200], $constraints->postPolicyContentLengthRange());
    }

    public function test_rejects_max_size_smaller_than_min_size(): void
    {
        $this->expectException(InvalidUploadConstraintException::class);
        new UploadConstraints(minSizeBytes: 10, maxSizeBytes: 5);
    }
}
