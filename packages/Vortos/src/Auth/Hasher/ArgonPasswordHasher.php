<?php

declare(strict_types=1);

namespace Vortos\Auth\Hasher;

use Vortos\Auth\Contract\PasswordHasherInterface;

/**
 * Argon2id password hasher using PHP's built-in password_hash.
 *
 * Argon2id is the recommended algorithm as of 2024 for password hashing.
 * It combines Argon2i's resistance to side-channel attacks with Argon2d's
 * resistance to GPU cracking attacks.
 *
 * PHP's password_hash automatically generates a cryptographic salt and
 * embeds it in the output string — you never manage salts manually.
 *
 * ## Cost tuning
 *
 * Increase memory_cost and time_cost for higher security at the cost of
 * more CPU/memory per login request. The defaults are conservative for
 * compatibility. Tune based on your server capacity — target 100-300ms
 * per hash on production hardware.
 *
 * Benchmark: php -r "
 *   $t = microtime(true);
 *   password_hash('test', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
 *   echo (microtime(true) - $t) * 1000 . 'ms';
 * "
 */
final class ArgonPasswordHasher implements PasswordHasherInterface
{
    private const MAX_PASSWORD_BYTES = 4096;

    public function __construct(
        private int $memoryCost = 65536,    // 64 MB
        private int $timeCost = 4,          // 4 iterations
        private int $threads = 1,
    ) {}

    public function hash(string $plaintext): string
    {
        self::guardLength($plaintext);

        return password_hash($plaintext, PASSWORD_ARGON2ID, [
            'memory_cost' => $this->memoryCost,
            'time_cost'   => $this->timeCost,
            'threads'     => $this->threads,
        ]);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        self::guardLength($plaintext);

        return password_verify($plaintext, $hash);
    }

    private static function guardLength(string $plaintext): void
    {
        if (\strlen($plaintext) > self::MAX_PASSWORD_BYTES) {
            throw new \InvalidArgumentException(
                \sprintf('Password exceeds maximum length of %d bytes.', self::MAX_PASSWORD_BYTES),
            );
        }
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => $this->memoryCost,
            'time_cost'   => $this->timeCost,
            'threads'     => $this->threads,
        ]);
    }
}
