<?php

declare(strict_types=1);

namespace Vortos\Audit\Integrity;

use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * Walks a chain (ordered by sequence ascending) and confirms it has not been tampered
 * with: contiguous sequence numbers, each record's prev_hash matching the running tail,
 * each content_hash recomputing to the stored value, and each signature valid.
 *
 * Verifying a full chain from genesis: pass $expectedPrevHash = GENESIS_HASH and the
 * chain's first sequence. Verifying a segment (e.g. after a checkpoint): pass the
 * checkpoint's tail hash and the next expected sequence.
 */
final class AuditChainVerifier
{
    public function __construct(private readonly AuditHashChain $chain) {}

    /**
     * @param list<StoredAuditEvent> $entries ordered by sequence ascending
     */
    public function verify(
        array  $entries,
        string $hmacKey,
        int    $expectedFirstSequence = 1,
        string $expectedPrevHash = AuditHashChain::GENESIS_HASH,
    ): ChainVerificationResult {
        $prevHash = $expectedPrevHash;
        $expectedSeq = $expectedFirstSequence;
        $verified = 0;

        foreach ($entries as $entry) {
            if ($entry->sequence !== $expectedSeq) {
                return ChainVerificationResult::broken(
                    $verified,
                    $entry->sequence,
                    "Non-contiguous sequence: expected {$expectedSeq}, found {$entry->sequence}.",
                );
            }

            if (!hash_equals($prevHash, $entry->prevHash)) {
                return ChainVerificationResult::broken(
                    $verified,
                    $entry->sequence,
                    'Broken link: prev_hash does not match the previous record.',
                );
            }

            $recomputed = $this->chain->contentHash($entry->event, $entry->prevHash);
            if (!hash_equals($recomputed, $entry->contentHash)) {
                return ChainVerificationResult::broken(
                    $verified,
                    $entry->sequence,
                    'Content tampered: recomputed content_hash does not match stored value.',
                );
            }

            $message = $this->chain->signingMessage($entry->event->id, $entry->sequence, $entry->contentHash, $entry->prevHash);
            if (!$this->chain->verifySignature($message, $entry->signature, $hmacKey)) {
                return ChainVerificationResult::broken(
                    $verified,
                    $entry->sequence,
                    'Invalid signature: HMAC does not verify against the configured key.',
                );
            }

            $prevHash = $entry->contentHash;
            $expectedSeq++;
            $verified++;
        }

        return ChainVerificationResult::ok($verified);
    }
}
