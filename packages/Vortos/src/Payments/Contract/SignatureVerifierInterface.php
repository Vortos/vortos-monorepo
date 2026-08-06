<?php

declare(strict_types=1);

namespace Vortos\Payments\Contract;

use Vortos\Payments\Exception\SignatureVerificationException;
use Vortos\Payments\Webhook\SignedPayload;

/**
 * Proves an inbound webhook came from the rail.
 *
 * This is the entire security boundary of a payment webhook. The endpoint is
 * public, unauthenticated and internet-reachable by necessity, so the
 * signature is the only thing standing between a stranger's POST and a
 * credited ledger.
 *
 * ── Rules every implementation must follow ────────────────────────────────
 * 1. Compare with `hash_equals`. A `===` on a signature leaks its own answer
 *    through timing, one byte at a time.
 * 2. Throw on failure, never return false. A boolean invites a caller to
 *    forget the `if`, and that particular forgotten `if` credits ledgers for
 *    anyone who finds the URL.
 * 3. Never echo the received or expected signature into an exception, a log
 *    or a response. An endpoint that reports how close a guess came is an
 *    oracle.
 * 4. Reject an absent or empty signature outright, before any parsing. Missing
 *    is not a special case of invalid to a caller that logs them differently.
 */
interface SignatureVerifierInterface
{
    /**
     * @throws SignatureVerificationException when the payload is not provably from the rail.
     */
    public function verify(SignedPayload $payload): void;
}
