<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

/**
 * Walks a sequence of {@see AuditEntry} rows (already ordered by `sequence` for one
 * env) and recomputes the hash chain + HMAC signatures, detecting:
 *
 *  - a mutated entry (content hash no longer matches its recorded fields)
 *  - a forged/invalid signature (HMAC mismatch)
 *  - a broken link (an entry's `prevHash` no longer matches its predecessor's
 *    `contentHash` — catches truncation against a checkpoint and reordering)
 *  - a sequence gap (a row missing or out of order)
 *
 * Pure and read-only — never mutates the ledger. {@see \Vortos\Observability\Console\AuditVerifyCommand}
 * is the CI/forensics entry point that exits non-zero on the first broken link.
 */
final class AuditChainVerifier
{
    public function __construct(
        private readonly AuditHashChain $chain = new AuditHashChain(),
    ) {
    }

    /**
     * @param list<AuditEntry> $entries Ordered by sequence ascending, for a single env
     */
    public function verify(array $entries, string $hmacKey): ChainVerificationResult
    {
        $expectedPrevHash = AuditHashChain::GENESIS_HASH;
        $expectedSequence = null;

        foreach ($entries as $entry) {
            if ($expectedSequence !== null && $entry->sequence !== $expectedSequence) {
                return ChainVerificationResult::broken(
                    $entry->sequence,
                    (string) $expectedSequence,
                    (string) $entry->sequence,
                    'sequence gap or reordering',
                );
            }

            if ($entry->prevHash !== $expectedPrevHash) {
                return ChainVerificationResult::broken(
                    $entry->sequence,
                    $expectedPrevHash,
                    $entry->prevHash,
                    'prevHash does not match predecessor content hash (truncated or reordered tail)',
                );
            }

            $recomputedContentHash = $this->chain->contentHash($entry->hashableFields(), $entry->prevHash);
            if (!hash_equals($recomputedContentHash, $entry->contentHash)) {
                return ChainVerificationResult::broken(
                    $entry->sequence,
                    $recomputedContentHash,
                    $entry->contentHash,
                    'content hash mismatch (entry was mutated)',
                );
            }

            $signingMessage = $this->chain->signingMessage($entry->entryId, $entry->sequence, $entry->contentHash, $entry->prevHash);
            if (!$this->chain->verifySignature($signingMessage, $entry->signature, $hmacKey)) {
                return ChainVerificationResult::broken(
                    $entry->sequence,
                    $this->chain->sign($signingMessage, $hmacKey),
                    $entry->signature,
                    'HMAC signature invalid (forged or signed with a different key)',
                );
            }

            $expectedPrevHash = $entry->contentHash;
            $expectedSequence = $entry->sequence + 1;
        }

        return ChainVerificationResult::intact();
    }
}
