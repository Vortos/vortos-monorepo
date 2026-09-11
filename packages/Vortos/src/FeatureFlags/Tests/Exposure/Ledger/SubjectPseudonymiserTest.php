<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure\Ledger;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exposure\Ledger\SubjectPseudonymiser;

/**
 * The ledger's only defence against a database dump.
 *
 * User ids are enumerable — the users table is the dictionary — so an unkeyed hash of one is
 * reversible by anyone holding both tables. These tests pin the properties that make the
 * stored token something other than obfuscation.
 */
final class SubjectPseudonymiserTest extends TestCase
{
    private const PEPPER = 'e6f1c0aa8b2d4f7391c5ae0b7d2f48366a9c1e5b0d8342f7ac916be4d05f2371';

    public function test_it_refuses_a_pepper_too_short_to_protect_anything(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 32 bytes/');

        new SubjectPseudonymiser('short');
    }

    public function test_it_refuses_an_absent_pepper(): void
    {
        // The empty string is the shape an unset env var arrives in, and it is the case that
        // matters: a deployment that forgot to configure one must fail loudly, not hash with
        // nothing and store reversible tokens.
        $this->expectException(InvalidArgumentException::class);

        new SubjectPseudonymiser('');
    }

    public function test_the_same_subject_always_yields_the_same_token(): void
    {
        $pseudonymiser = new SubjectPseudonymiser(self::PEPPER);

        // Stability is what keeps a subject in one experiment arm across days. Without it
        // every day would look like a fresh population and no experiment could run longer
        // than one.
        self::assertSame(
            $pseudonymiser->pseudonymise('user-42'),
            $pseudonymiser->pseudonymise('user-42'),
        );
    }

    public function test_different_subjects_yield_different_tokens(): void
    {
        $pseudonymiser = new SubjectPseudonymiser(self::PEPPER);

        self::assertNotSame(
            $pseudonymiser->pseudonymise('user-42'),
            $pseudonymiser->pseudonymise('user-43'),
        );
    }

    public function test_the_token_is_not_a_plain_hash_of_the_subject(): void
    {
        $pseudonymiser = new SubjectPseudonymiser(self::PEPPER);

        // The whole point. If this ever equalled an unkeyed digest, an attacker with a dump
        // could rebuild the mapping by hashing the users table.
        self::assertNotSame(
            substr(hash('sha256', 'user-42'), 0, SubjectPseudonymiser::TOKEN_LENGTH),
            $pseudonymiser->pseudonymise('user-42'),
        );
    }

    public function test_a_different_pepper_yields_a_different_token(): void
    {
        $a = new SubjectPseudonymiser(self::PEPPER);
        $b = new SubjectPseudonymiser(strrev(self::PEPPER));

        // Corollary: rotating the pepper severs subject identity, which is why rotation must
        // never happen mid-experiment.
        self::assertNotSame($a->pseudonymise('user-42'), $b->pseudonymise('user-42'));
    }

    public function test_the_token_is_128_bits_of_hex(): void
    {
        $token = (new SubjectPseudonymiser(self::PEPPER))->pseudonymise('user-42');

        // Pinned because this is the leading component of the ledger's primary key: widening
        // it is a schema change, not a refactor.
        self::assertSame(32, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }
}
