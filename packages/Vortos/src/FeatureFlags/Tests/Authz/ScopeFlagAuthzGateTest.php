<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Authz;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Authz\FlagScopeCheckerInterface;
use Vortos\FeatureFlags\Authz\ScopeFlagAuthzGate;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;

final class ScopeFlagAuthzGateTest extends TestCase
{
    public function test_flag_without_required_scope_always_allowed_without_calling_checker(): void
    {
        $checker = new class implements FlagScopeCheckerInterface {
            public bool $called = false;
            public function isGranted(string $scope, FlagContext $context): bool
            {
                $this->called = true;
                return false;
            }
        };
        $gate = new ScopeFlagAuthzGate($checker);

        $this->assertTrue($gate->allows($this->flag(null), new FlagContext('u1')));
        $this->assertFalse($checker->called, 'no requiredScope → zero authz calls');
    }

    public function test_granted_scope_allows(): void
    {
        $gate = new ScopeFlagAuthzGate($this->checker(granted: true));

        $this->assertTrue($gate->allows($this->flag('billing.read.any'), new FlagContext('u1')));
    }

    public function test_missing_scope_denies(): void
    {
        $gate = new ScopeFlagAuthzGate($this->checker(granted: false));

        $this->assertFalse($gate->allows($this->flag('billing.read.any'), new FlagContext('u1')));
    }

    public function test_checker_error_fails_closed(): void
    {
        $checker = new class implements FlagScopeCheckerInterface {
            public function isGranted(string $scope, FlagContext $context): bool
            {
                throw new \RuntimeException('authz backend down');
            }
        };
        $gate = new ScopeFlagAuthzGate($checker);

        $this->assertFalse(
            $gate->allows($this->flag('billing.read.any'), new FlagContext('u1')),
            'any error must fail closed (deny)',
        );
    }

    public function test_decision_is_memoized_per_subject_and_scope(): void
    {
        $checker = new class implements FlagScopeCheckerInterface {
            public int $calls = 0;
            public function isGranted(string $scope, FlagContext $context): bool
            {
                $this->calls++;
                return true;
            }
        };
        $gate = new ScopeFlagAuthzGate($checker);
        $flag = $this->flag('billing.read.any');

        $gate->allows($flag, new FlagContext('u1'));
        $gate->allows($flag, new FlagContext('u1'));
        $gate->allows($flag, new FlagContext('u1'));

        $this->assertSame(1, $checker->calls, 'same subject+scope checked once per request');
    }

    private function checker(bool $granted): FlagScopeCheckerInterface
    {
        return new class($granted) implements FlagScopeCheckerInterface {
            public function __construct(private bool $granted) {}
            public function isGranted(string $scope, FlagContext $context): bool
            {
                return $this->granted;
            }
        };
    }

    private function flag(?string $requiredScope): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag(
            'id', 'gated', '', true, [], null, $now, $now,
            requiredScope: $requiredScope,
        );
    }
}
