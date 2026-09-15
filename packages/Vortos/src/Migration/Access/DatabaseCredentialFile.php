<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Vortos\Foundation\Secret\SecretValue;
use Vortos\Persistence\Access\DatabaseRoleName;

/**
 * Reads a role's password from the env file that delivers that audience's DSN.
 *
 * WHY THE ENV FILE. The file a process boots with is the credential that matters: setting a role's password from
 * anything else can leave the database and the delivered DSN disagreeing, which surfaces as an outage at the next
 * deploy. Reading the same file makes the verifier match what will log in, and proves the file's DSN names the
 * role it is meant for — an env file pointing the backup node at the runtime role is refused here, not discovered
 * as a permission error in production.
 *
 * The path arrives as an argument; the password never does. No error names the value.
 */
final class DatabaseCredentialFile
{
    public const DSN_KEY = 'VORTOS_WRITE_DB_DSN';

    public static function passwordFor(DatabaseRoleName $role, string $path): SecretValue
    {
        if (!is_file($path) || is_link($path)) {
            throw new \InvalidArgumentException(sprintf('Credential file "%s" is not a regular file.', $path));
        }

        $perms = fileperms($path);
        if ($perms === false || ($perms & 0o007) !== 0) {
            throw new \InvalidArgumentException(sprintf('Credential file "%s" is readable by other users; restrict it (0600 or 0640) before use.', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException(sprintf('Credential file "%s" could not be read.', $path));
        }

        $dsn = null;
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (str_starts_with($line, self::DSN_KEY . '=')) {
                $dsn = trim(substr($line, \strlen(self::DSN_KEY) + 1));
            }
        }
        if ($dsn === null || $dsn === '') {
            throw new \InvalidArgumentException(sprintf('Credential file "%s" does not set %s.', $path, self::DSN_KEY));
        }
        if (\strlen($dsn) >= 2 && ($dsn[0] === '"' || $dsn[0] === "'") && $dsn[-1] === $dsn[0]) {
            $dsn = substr($dsn, 1, -1);
        }

        $parts = parse_url($dsn);
        if ($parts === false || !\in_array($parts['scheme'] ?? '', ['pgsql', 'postgres', 'postgresql'], true)) {
            throw new \InvalidArgumentException(sprintf('%s in "%s" is not a PostgreSQL DSN.', self::DSN_KEY, $path));
        }

        $user = rawurldecode((string) ($parts['user'] ?? ''));
        if ($user !== $role->value) {
            throw new \InvalidArgumentException(sprintf(
                '%s in "%s" connects as "%s", but it is used as the credential of role "%s".',
                self::DSN_KEY,
                $path,
                $user,
                $role->value,
            ));
        }

        $password = rawurldecode((string) ($parts['pass'] ?? ''));
        if ($password === '') {
            throw new \InvalidArgumentException(sprintf('%s in "%s" carries no password.', self::DSN_KEY, $path));
        }

        return SecretValue::fromString($password);
    }
}
