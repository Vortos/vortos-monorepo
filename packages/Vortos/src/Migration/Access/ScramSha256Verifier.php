<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Vortos\Foundation\Secret\SecretValue;

/**
 * Derives the SCRAM-SHA-256 verifier PostgreSQL stores for a password (RFC 5802 / RFC 7677, the format of
 * pg_authid.rolpassword), so `ALTER ROLE … PASSWORD` can be sent the verifier instead of the password.
 *
 * WHY. A plaintext password in an ALTER ROLE statement travels to the server as statement text: it is visible in
 * pg_stat_activity while it runs, lands in the server log whenever statement logging or an error includes it, and
 * lives in whatever file or pipe carried the SQL. The verifier cannot be used to log in; PostgreSQL accepts it
 * verbatim and never sees the password.
 *
 * Passwords are restricted to printable ASCII without spaces: PostgreSQL applies SASLprep before deriving, which
 * is the identity on that set, so the verifier computed here is the one the server computes at login.
 */
final class ScramSha256Verifier
{
    public const ITERATIONS = 4096;

    public static function derive(SecretValue $password, ?string $salt = null): string
    {
        $plain = $password->reveal();
        if (preg_match('/^[\x21-\x7E]{16,}$/', $plain) !== 1) {
            throw new \InvalidArgumentException(
                'Database passwords must be at least 16 printable ASCII characters without spaces, so SASLprep leaves them unchanged.',
            );
        }

        $salt ??= random_bytes(16);
        if (\strlen($salt) !== 16) {
            throw new \InvalidArgumentException('The SCRAM salt must be 16 bytes.');
        }

        $salted = hash_pbkdf2('sha256', $plain, $salt, self::ITERATIONS, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);

        return sprintf(
            'SCRAM-SHA-256$%d:%s$%s:%s',
            self::ITERATIONS,
            base64_encode($salt),
            base64_encode(hash('sha256', $clientKey, true)),
            base64_encode(hash_hmac('sha256', 'Server Key', $salted, true)),
        );
    }
}
