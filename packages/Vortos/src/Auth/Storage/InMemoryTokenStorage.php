<?php

declare(strict_types=1);

namespace Vortos\Auth\Storage;

use Vortos\Auth\Contract\TokenStorageInterface;

/**
 * In-memory token storage for testing.
 *
 * Stores tokens in a plain PHP array. TTL is enforced lazily on consume().
 * Reset between tests with clear().
 */
final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<string, array{userId: string, expiresAt: int}> */
    private array $tokens = [];

    public function store(string $jti, string $userId, int $expiresAt): void
    {
        $this->tokens[$jti] = ['userId' => $userId, 'expiresAt' => $expiresAt];
    }

    public function consume(string $jti): ?string
    {
        if (!isset($this->tokens[$jti])) {
            return null;
        }

        if ($this->tokens[$jti]['expiresAt'] < time()) {
            unset($this->tokens[$jti]);
            return null;
        }

        $userId = $this->tokens[$jti]['userId'];
        unset($this->tokens[$jti]);
        return $userId;
    }

    public function revoke(string $jti): void
    {
        unset($this->tokens[$jti]);
    }

    public function revokeAllForUser(string $userId): void
    {
        foreach ($this->tokens as $jti => $data) {
            if ($data['userId'] === $userId) {
                unset($this->tokens[$jti]);
            }
        }
    }

    public function clear(): void
    {
        $this->tokens = [];
    }
}
