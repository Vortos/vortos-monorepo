<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model\Signature;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\Signature\VerificationResult;
use Vortos\Security\SupplyChain\Model\SignatureMismatchException;

final class VerificationResultTest extends TestCase
{
    public function test_success(): void
    {
        $result = VerificationResult::success();
        self::assertTrue($result->ok);
        self::assertSame([], $result->reasons);
        $result->assertVerified();
    }

    public function test_failure(): void
    {
        $result = VerificationResult::failure(['bad sig']);
        self::assertFalse($result->ok);
        self::assertSame(['bad sig'], $result->reasons);
    }

    public function test_assert_verified_throws_on_failure(): void
    {
        $result = VerificationResult::failure(['reason1', 'reason2']);
        $this->expectException(SignatureMismatchException::class);
        $this->expectExceptionMessage('reason1');
        $result->assertVerified();
    }

    public function test_failure_rejects_empty_reasons(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VerificationResult::failure([]);
    }
}
