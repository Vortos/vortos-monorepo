<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health;

use Symfony\Component\HttpFoundation\Request;

final class HealthDetailPolicy
{
    public const NEVER = 'never';
    public const TOKEN = 'token';
    public const DEBUG = 'debug';
    public const ALWAYS = 'always';

    public function __construct(
        private readonly string $policy = self::DEBUG,
        private readonly ?string $token = null,
        private readonly string $appEnv = 'prod',
        private readonly bool $appDebug = false,
        private readonly bool $exposeRawErrors = false,
        private readonly bool $exposeDegradedNames = true,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            self::normalizePolicy((string) ($_ENV['HEALTH_DETAILS'] ?? 'never')),
            $_ENV['HEALTH_TOKEN'] ?? null,
            (string) ($_ENV['APP_ENV'] ?? 'prod'),
            self::envBool($_ENV['APP_DEBUG'] ?? false),
            self::envBool($_ENV['HEALTH_EXPOSE_ERRORS'] ?? false),
            self::envBool($_ENV['VORTOS_HEALTH_PUBLIC_DEGRADED_NAMES'] ?? true),
        );
    }

    public function allowsDetails(Request $request): bool
    {
        return match ($this->policy) {
            self::ALWAYS => true,
            self::NEVER => false,
            self::TOKEN => $this->hasValidToken($request),
            self::DEBUG => $this->isDebugEnvironment() || $this->hasValidToken($request),
            default => false,
        };
    }

    public function allowsRawErrors(): bool
    {
        return $this->exposeRawErrors || $this->isDebugEnvironment();
    }

    /**
     * R8-5: whether the anonymous readiness body may list the names of non-passing checks. On by
     * default (names are non-sensitive); set VORTOS_HEALTH_PUBLIC_DEGRADED_NAMES=false to suppress.
     */
    public function allowsPublicDegradedNames(): bool
    {
        return $this->exposeDegradedNames;
    }

    /**
     * R8-5: whether a health token is configured at all. The authenticated /health/detail endpoint
     * uses this to distinguish "not configured" (feature unavailable) from "wrong token".
     */
    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /**
     * R8-5: constant-time check of the presented X-Health-Token against the configured one, independent
     * of the HEALTH_DETAILS policy — so an operator with the token can always reach full detail, even
     * when the anonymous policy is "never". Returns false when no token is configured.
     */
    public function matchesToken(Request $request): bool
    {
        return $this->hasValidToken($request);
    }

    private function hasValidToken(Request $request): bool
    {
        if ($this->token === null || $this->token === '') {
            return false;
        }

        return hash_equals($this->token, (string) $request->headers->get('X-Health-Token', ''));
    }

    private function isDebugEnvironment(): bool
    {
        return $this->appDebug || $this->appEnv === 'dev';
    }

    private static function normalizePolicy(string $policy): string
    {
        $policy = strtolower(trim($policy));

        return in_array($policy, [self::NEVER, self::TOKEN, self::DEBUG, self::ALWAYS], true)
            ? $policy
            : self::NEVER;
    }

    private static function envBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
