<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\TokenFreshness;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Contract\TokenFreshnessGuardInterface;
use Vortos\Auth\TokenFreshness\CompositeTokenFreshnessGuard;

final class CompositeTokenFreshnessGuardTest extends TestCase
{
    public function test_returns_null_when_all_guards_pass(): void
    {
        $g1 = $this->passingGuard();
        $g2 = $this->passingGuard();
        $composite = new CompositeTokenFreshnessGuard($g1, $g2);

        $this->assertNull($composite->check('user-1', 0, time()));
    }

    public function test_returns_first_rejection_reason(): void
    {
        $g1 = $this->passingGuard();
        $g2 = $this->failingGuard('stale version');
        $g3 = $this->failingGuard('min_iat exceeded');

        $composite = new CompositeTokenFreshnessGuard($g1, $g2, $g3);

        $this->assertSame('stale version', $composite->check('user-1', 0, time()));
    }

    public function test_returns_null_with_no_guards(): void
    {
        $composite = new CompositeTokenFreshnessGuard();

        $this->assertNull($composite->check('user-1', 0, time()));
    }

    private function passingGuard(): TokenFreshnessGuardInterface
    {
        return new class implements TokenFreshnessGuardInterface {
            public function check(string $userId, int $authzVersion, int $issuedAt): ?string
            {
                return null;
            }
        };
    }

    private function failingGuard(string $reason): TokenFreshnessGuardInterface
    {
        return new class($reason) implements TokenFreshnessGuardInterface {
            public function __construct(private string $reason) {}
            public function check(string $userId, int $authzVersion, int $issuedAt): ?string
            {
                return $this->reason;
            }
        };
    }
}
