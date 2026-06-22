<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;

/**
 * Authorization-scope gate (Block 9). Vetoes a flag when its `requiredScope` is not held by
 * the current subject.
 *
 *  - No `requiredScope` → always allowed (the common case; zero authz calls).
 *  - **Fail-closed:** any error from the checker denies (flag off / safe default).
 *  - **Deny-only:** returns a boolean the caller ANDs with the evaluation result — it can
 *    never force a flag on.
 *  - **Memoized per request** by (subject, scope) so N evaluations cost at most one authz
 *    check per distinct scope. Reset between requests (worker mode).
 */
final class ScopeFlagAuthzGate implements FlagAuthzGateInterface, ResetInterface
{
    /** @var array<string,bool> */
    private array $memo = [];

    public function __construct(private readonly FlagScopeCheckerInterface $checker) {}

    public function allows(FeatureFlag $flag, FlagContext $context): bool
    {
        if ($flag->requiredScope === null) {
            return true;
        }

        $key = ($context->userId ?? '__anon__') . '|' . $flag->requiredScope;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        try {
            $allowed = $this->checker->isGranted($flag->requiredScope, $context);
        } catch (\Throwable) {
            $allowed = false; // fail closed
        }

        return $this->memo[$key] = $allowed;
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
