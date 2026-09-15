<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Secret\SecretValue;
use Vortos\Migration\Access\ScramSha256Verifier;

final class ScramSha256VerifierTest extends TestCase
{
    /**
     * Produced by PostgreSQL 18 itself (postgres:18-alpine, 2026-09-15):
     * `CREATE ROLE vec LOGIN PASSWORD 'correct-horse-battery-staple-42'` then pg_authid.rolpassword.
     */
    private const POSTGRES_VERIFIER = 'SCRAM-SHA-256$4096:i+jxdU799p9h8HcSu2nJNQ==$pMOeILE39UdBFl6rygWjajYXwsNEu6M87UOKqGF7omg=:MYD82E+rH2Q2Gv1tIFu8P75cqdqV1yO5WOpXJcQPcVY=';

    public function test_it_derives_exactly_the_verifier_postgresql_stores_for_the_same_salt(): void
    {
        $salt = base64_decode('i+jxdU799p9h8HcSu2nJNQ==', true);

        self::assertSame(
            self::POSTGRES_VERIFIER,
            ScramSha256Verifier::derive(SecretValue::fromString('correct-horse-battery-staple-42'), (string) $salt),
        );
    }

    public function test_each_derivation_uses_a_fresh_salt_and_never_contains_the_password(): void
    {
        $password = SecretValue::fromString('correct-horse-battery-staple-42');

        $first = ScramSha256Verifier::derive($password);
        $second = ScramSha256Verifier::derive($password);

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('#^SCRAM-SHA-256\$4096:[A-Za-z0-9+/]{22}==\$[A-Za-z0-9+/]{43}=:[A-Za-z0-9+/]{43}=$#', $first);
        self::assertStringNotContainsString('correct-horse', $first);
    }

    /** @return iterable<string, array{string}> */
    public static function unusablePasswords(): iterable
    {
        yield 'too short' => ['short-pass'];
        yield 'space' => ['correct horse battery staple'];
        yield 'non-ascii (SASLprep would change it)' => ['correct-horse-battery-stäple'];
    }

    #[DataProvider('unusablePasswords')]
    public function test_passwords_saslprep_would_alter_or_that_are_weak_are_refused(string $password): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScramSha256Verifier::derive(SecretValue::fromString($password));
    }
}
