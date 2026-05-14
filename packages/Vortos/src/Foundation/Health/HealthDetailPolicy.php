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
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            self::normalizePolicy((string) ($_ENV['HEALTH_DETAILS'] ?? 'never')),
            $_ENV['HEALTH_TOKEN'] ?? null,
            (string) ($_ENV['APP_ENV'] ?? 'prod'),
            self::envBool($_ENV['APP_DEBUG'] ?? false),
            self::envBool($_ENV['HEALTH_EXPOSE_ERRORS'] ?? false),
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
