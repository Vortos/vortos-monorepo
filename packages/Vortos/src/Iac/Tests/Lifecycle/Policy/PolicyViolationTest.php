<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle\Policy;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\Policy\PolicyViolation;

final class PolicyViolationTest extends TestCase
{
    public function test_valid_violation(): void
    {
        $v = new PolicyViolation('no-public-bucket', 'aws_s3_bucket.x', 'Must not be public');
        $this->assertSame('no-public-bucket', $v->ruleId);
        $this->assertSame('aws_s3_bucket.x', $v->address);
        $this->assertSame('Must not be public', $v->message);
    }

    public function test_empty_rule_id_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PolicyViolation('', 'r.x', 'msg');
    }
}
