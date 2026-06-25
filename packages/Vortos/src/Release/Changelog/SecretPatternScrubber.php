<?php

declare(strict_types=1);

namespace Vortos\Release\Changelog;

final class SecretPatternScrubber
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const PATTERNS = [
        // AWS access key IDs
        '/\bAKIA[0-9A-Z]{16}\b/',
        // AWS secret keys (40 chars base64-ish)
        '/(?<=[^A-Za-z0-9\/+=]|^)[A-Za-z0-9\/+=]{40}(?=[^A-Za-z0-9\/+=]|$)/',
        // Generic API keys/tokens (common env-var-style assignments)
        '/(?:api[_-]?key|api[_-]?secret|access[_-]?token|auth[_-]?token|secret[_-]?key|private[_-]?key|client[_-]?secret)\s*[:=]\s*["\']?[A-Za-z0-9\-_.\/+=]{16,}["\']?/i',
        // RSA/EC/OPENSSH private key blocks
        '/-----BEGIN\s+(?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----[\s\S]*?-----END\s+(?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----/',
        // PGP private key blocks
        '/-----BEGIN PGP PRIVATE KEY BLOCK-----[\s\S]*?-----END PGP PRIVATE KEY BLOCK-----/',
        // GitHub personal access tokens
        '/\bgh[ps]_[A-Za-z0-9]{36,}\b/',
        // GitHub fine-grained tokens
        '/\bgithub_pat_[A-Za-z0-9_]{22,}\b/',
        // Slack tokens
        '/\bxox[bporsae]-[A-Za-z0-9\-]{10,}\b/',
        // Generic bearer tokens in headers
        '/Bearer\s+[A-Za-z0-9\-_.~+\/]{20,}/',
        // JWT tokens (3 base64url segments)
        '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
        // Hex-encoded secrets (64+ chars, likely SHA-256 keys)
        '/(?:secret|token|key|password|credential)\s*[:=]\s*["\']?[0-9a-f]{64,}["\']?/i',
        // Database connection strings with passwords
        '/(?:mysql|postgres|postgresql|mongodb|redis):\/\/[^:]+:[^@\s]{8,}@/i',
    ];

    public function scrub(string $text): string
    {
        return (string) preg_replace(self::PATTERNS, self::REDACTED, $text);
    }
}
