<?php

declare(strict_types=1);

namespace Vortos\Security\Signing;

use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies HMAC request signatures.
 *
 * Stateless — instantiated once, called per-request.
 * All comparisons are constant-time (hash_equals) to prevent timing attacks.
 */
final class SignatureVerifier
{
    /**
     * Verifies the signature on the request.
     *
     * @param string $secret   The raw HMAC secret (after env/secrets resolution).
     * @param string $header   Name of the header carrying the signature.
     * @param string $algorithm HMAC algorithm — default sha256.
     */
    public function verify(Request $request, string $secret, string $header, string $algorithm = 'sha256'): bool
    {
        if ($secret === '') {
            throw new \RuntimeException(
                'Signature verification secret is empty. Set the env variable referenced in #[RequiresSignature].'
            );
        }

        $receivedHeader = $request->headers->get($header, '');
        if ($receivedHeader === '') {
            return false;
        }

        // Strip common prefixes (sha256=, sha1=, v0=, etc.)
        $received = $this->stripPrefix($receivedHeader);

        $body     = $request->getContent();
        $expected = hash_hmac($algorithm, $body, $secret);

        return hash_equals($expected, $received);
    }

    /**
     * Verifies the signature with timestamp-based replay protection.
     *
     * Stripe uses a combined header: t=1234567890,v1=<hex>
     * Others use a separate timestamp header.
     *
     * @param string $signatureHeader  Header carrying the signature (may also carry timestamp).
     * @param string $timestampHeader  Separate timestamp header, or same as $signatureHeader for combined.
     */
    public function verifyWithTimestamp(
        Request $request,
        string  $secret,
        string  $signatureHeader,
        string  $timestampHeader,
        int     $replayWindowSeconds,
        string  $algorithm = 'sha256',
    ): bool {
        if ($secret === '') {
            throw new \RuntimeException(
                'Signature verification secret is empty. Set the env variable referenced in #[RequiresSignature].'
            );
        }

        $sigHeader = $request->headers->get($signatureHeader, '');
        if ($sigHeader === '') {
            return false;
        }

        // Stripe combined header: t=1496734051,v1=abc123...
        if (str_contains($sigHeader, 't=') && str_contains($sigHeader, 'v1=')) {
            return $this->verifyStripeStyle($request, $secret, $sigHeader, $replayWindowSeconds, $algorithm);
        }

        // Separate timestamp header
        $timestamp = (int) $request->headers->get($timestampHeader, '0');
        if (abs(time() - $timestamp) > $replayWindowSeconds) {
            return false;
        }

        // Sign the timestamp + body together (common pattern)
        $payload  = $timestamp . '.' . $request->getContent();
        $received = $this->stripPrefix($sigHeader);
        $expected = hash_hmac($algorithm, $payload, $secret);

        return hash_equals($expected, $received);
    }

    /**
     * Resolves 'env:VAR_NAME' references in secret strings.
     * Plain strings are returned as-is.
     *
     * @throws \RuntimeException if the resolved secret is empty
     */
    public function resolveSecret(string $secret): string
    {
        if (str_starts_with($secret, 'env:')) {
            $varName  = substr($secret, 4);
            $resolved = $_ENV[$varName] ?? '';
            if ($resolved === '') {
                throw new \RuntimeException(
                    "Signature secret env variable \"{$varName}\" is not set. "
                    . 'Add it to your .env file to enable webhook signature verification.'
                );
            }
            return $resolved;
        }

        if ($secret === '') {
            throw new \RuntimeException(
                'Signature verification secret is empty. Pass a non-empty secret or use env:VAR_NAME.'
            );
        }

        return $secret;
    }

    private function verifyStripeStyle(
        Request $request,
        string  $secret,
        string  $header,
        int     $replayWindow,
        string  $algorithm,
    ): bool {
        // Parse: t=1496734051,v1=abc123,...
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = $parts['v1'] ?? '';

        if ($timestamp === 0 || $signature === '') {
            return false;
        }

        if (abs(time() - $timestamp) > $replayWindow) {
            return false;
        }

        $payload  = $timestamp . '.' . $request->getContent();
        $expected = hash_hmac($algorithm, $payload, $secret);

        return hash_equals($expected, $signature);
    }

    private function stripPrefix(string $value): string
    {
        // Remove sha256=, sha1=, v0=, etc.
        if (preg_match('/^[a-z0-9]+=([a-f0-9]+)$/i', $value, $m)) {
            return $m[1];
        }
        return $value;
    }
}
