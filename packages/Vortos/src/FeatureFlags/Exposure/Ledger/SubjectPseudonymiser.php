<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

use SensitiveParameter;

/**
 * Turns an analytics identity into the stable, non-reversible token the ledger stores.
 *
 * ## Why a keyed HMAC and not a plain hash
 *
 * A plain `sha256(userId)` is not pseudonymisation, it is obfuscation, and it fails against
 * the only attacker who matters here: one holding a database dump. User ids are drawn from a
 * small, enumerable space (UUIDs from a known table, integers, email addresses), so an
 * attacker who can hash candidates can simply hash them all and join the result back to the
 * users table. The dictionary is the `users` table itself.
 *
 * Keying the hash with a secret the dump does not contain breaks that: without the pepper
 * there is no candidate to test. This is the difference the GDPR draws between
 * pseudonymised data (a technical measure, still personal data, but demonstrably protective)
 * and data that merely looks scrambled.
 *
 * ## Why the pepper is required rather than defaulted
 *
 * A default pepper is the same as no pepper: it ships in the source tree, so it is in every
 * dump-holder's hands already. {@see self::__construct()} therefore refuses to build with a
 * weak one, and the DI extension surfaces that at container-compile time — a boot failure an
 * operator must fix, not a silent downgrade to a hash anyone can reverse. A ledger that
 * quietly stored reversible identities would be worse than no ledger, because it would be
 * trusted.
 *
 * ## Why truncation to 128 bits is safe
 *
 * The output is halved to 32 hex characters. The property required of it is collision
 * resistance across the subjects in one deployment, not preimage resistance of a general
 * hash; at 2^128 the birthday bound puts a first collision around 2^64 subjects, which is
 * not a scale any tenant database reaches. The saving is real: this token is the leading
 * component of the ledger's primary key, so every stored byte is paid for again in the index.
 */
final readonly class SubjectPseudonymiser
{
    /** Below this a pepper is guessable or absent; either way it provides no protection. */
    public const MIN_PEPPER_BYTES = 32;

    /** Hex characters kept from the HMAC. 32 hex = 128 bits. */
    public const TOKEN_LENGTH = 32;

    public function __construct(
        #[SensitiveParameter]
        private string $pepper,
    ) {
        if (strlen($this->pepper) < self::MIN_PEPPER_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'The exposure ledger pepper must be at least %d bytes; got %d. Generate one with '
                . '`openssl rand -hex 32` and set FEATURE_FLAGS_LEDGER_PEPPER. A short or absent '
                . 'pepper makes every stored subject token reversible by anyone holding a database '
                . 'dump, because user ids are enumerable.',
                self::MIN_PEPPER_BYTES,
                strlen($this->pepper),
            ));
        }
    }

    /**
     * The stored token for an analytics identity.
     *
     * Stable for the lifetime of the pepper, which is what keeps a subject in one experiment
     * arm across days. Rotating the pepper is therefore a deliberate act that severs every
     * in-flight experiment: the same person becomes a new subject, appears in both arms of
     * anything still running, and the readout for that window is no longer sound. Rotate
     * between experiments, never during one.
     */
    public function pseudonymise(#[SensitiveParameter] string $analyticsId): string
    {
        return substr(hash_hmac('sha256', $analyticsId, $this->pepper), 0, self::TOKEN_LENGTH);
    }
}
