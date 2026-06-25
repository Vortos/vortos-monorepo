<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle\Policy;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\Policy\PolicyResult;
use Vortos\Iac\Lifecycle\Policy\PolicyViolation;

final class PolicyResultTest extends TestCase
{
    public function test_pass_has_no_violations(): void
    {
        $result = PolicyResult::pass();
        $this->assertTrue($result->passed());
        $this->assertSame([], $result->violations);
    }

    public function test_fail_has_violations(): void
    {
        $result = PolicyResult::fail([
            new PolicyViolation('no-public-bucket', 'aws_s3_bucket.test', 'Bucket must not be public'),
        ]);
        $this->assertFalse($result->passed());
        $this->assertCount(1, $result->violations);
    }

    public function test_empty_violations_is_pass(): void
    {
        $result = new PolicyResult([]);
        $this->assertTrue($result->passed());
    }
}
