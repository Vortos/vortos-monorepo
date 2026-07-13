<?php

declare(strict_types=1);

namespace Vortos\Auth\Storage;

use Vortos\Auth\Contract\TokenStorageInterface;

/**
 * In-memory token storage for testing.
 *
 * Stores tokens in a plain PHP array. TTL is enforced lazily on consume().
 * Reset between tests with clear().
 *
 * Mirrors {@see RedisTokenStorage}'s rotation-grace semantics so tests and local dev behave
 * identically to production: a just-rotated jti re-presented within the grace window is
 * recognised as benign rather than reuse.
 */
final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<string, array{userId: string, expiresAt: int}> */
    private array $tokens = [];

    /** @var array<string, array{userId: string, expiresAt: int}> jti → recently-consumed grace marker */
    private array $grace = [];

    /**
     * @param int $rotationGraceSeconds Grace window during which a just-rotated jti may be
     *                                  re-consumed without tripping reuse detection. 0 = strict.
     */
    public function __construct(private int $rotationGraceSeconds = 0) {}

    public function store(string $jti, string $userId, int $expiresAt): void
    {
        $this->tokens[$jti] = ['userId' => $userId, 'expiresAt' => $expiresAt];
    }

    public function consume(string $jti): ?string
    {
        if (isset($this->tokens[$jti])) {
            if ($this->tokens[$jti]['expiresAt'] < time()) {
                unset($this->tokens[$jti]);
                return null;
            }

            $userId = $this->tokens[$jti]['userId'];
            unset($this->tokens[$jti]);

            if ($this->rotationGraceSeconds > 0) {
                $this->grace[$jti] = ['userId' => $userId, 'expiresAt' => time() + $this->rotationGraceSeconds];
            }

            return $userId;
        }

        // Primary miss — a just-rotated token re-presented within the grace window is benign.
        if ($this->rotationGraceSeconds > 0 && isset($this->grace[$jti])) {
            if ($this->grace[$jti]['expiresAt'] < time()) {
                unset($this->grace[$jti]);
                return null;
            }
            return $this->grace[$jti]['userId'];
        }

        return null;
    }

    public function revoke(string $jti): void
    {
        unset($this->tokens[$jti], $this->grace[$jti]);
    }

    public function revokeAllForUser(string $userId): void
    {
        foreach ($this->tokens as $jti => $data) {
            if ($data['userId'] === $userId) {
                unset($this->tokens[$jti]);
            }
        }
        foreach ($this->grace as $jti => $data) {
            if ($data['userId'] === $userId) {
                unset($this->grace[$jti]);
            }
        }
    }

    public function clear(): void
    {
        $this->tokens = [];
        $this->grace = [];
    }
}
