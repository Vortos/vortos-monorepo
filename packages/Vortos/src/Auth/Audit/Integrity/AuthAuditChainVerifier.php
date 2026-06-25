<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Integrity;

use Vortos\Auth\Audit\AuditEntry;

final class AuthAuditChainVerifier
{
    public function __construct(
        private readonly AuthAuditHashChain $chain = new AuthAuditHashChain(),
    ) {}

    /**
     * @param list<AuditEntry> $entries Ordered by sequence ascending
     * @param string $startPrevHash Chain tail to resume from when verifying a page after a prior call
     *     (defaults to genesis for a from-scratch verification of the whole chain).
     * @param int|null $startSequence Expected sequence of the first entry in $entries when resuming;
     *     null skips the check for the first entry only (from-scratch verification).
     */
    public function verify(
        array $entries,
        string $hmacKey,
        string $startPrevHash = AuthAuditHashChain::GENESIS_HASH,
        ?int $startSequence = null,
    ): ChainVerificationResult {
        $expectedPrevHash = $startPrevHash;
        $expectedSequence = $startSequence;

        foreach ($entries as $entry) {
            if ($entry->sequence === null || $entry->contentHash === null || $entry->signature === null || $entry->prevHash === null) {
                return ChainVerificationResult::broken(
                    $expectedSequence ?? 0,
                    'chained',
                    'unchained',
                    'entry missing integrity fields (not a chained entry)',
                );
            }

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
                    'prevHash does not match predecessor content hash',
                );
            }

            $hashable = [
                'id' => $entry->id,
                'user_id' => $entry->userId,
                'action' => $entry->action,
                'resource_id' => $entry->resourceId,
                'ip_address' => $entry->ipAddress,
                'user_agent' => $entry->userAgent,
                'occurred_at' => $entry->occurredAt->format(\DateTimeInterface::ATOM),
                'metadata' => $entry->metadata,
            ];

            $recomputedContentHash = $this->chain->contentHash($hashable, $entry->prevHash);
            if (!hash_equals($recomputedContentHash, $entry->contentHash)) {
                return ChainVerificationResult::broken(
                    $entry->sequence,
                    $recomputedContentHash,
                    $entry->contentHash,
                    'content hash mismatch (entry was mutated)',
                );
            }

            $signingMessage = $this->chain->signingMessage($entry->id, $entry->sequence, $entry->contentHash, $entry->prevHash);
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
