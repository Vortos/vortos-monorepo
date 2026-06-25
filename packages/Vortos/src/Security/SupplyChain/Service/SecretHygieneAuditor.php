<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

final class SecretHygieneAuditor
{
    private const LEAK_PATTERNS = [
        'aws_access_key' => '/(?:AKIA|ABIA|ACCA|ASIA)[0-9A-Z]{16}/',
        'private_key' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/',
        'github_token' => '/gh[ps]_[A-Za-z0-9_]{36,}/',
        'generic_secret' => '/(?:password|secret|token|api_key|apikey)\s*[=:]\s*["\']?[A-Za-z0-9\/+=]{16,}/',
    ];

    /**
     * @param list<SecretAuditEntry> $entries
     * @param list<string>          $allowlist Pattern names to skip
     * @return list<SecretHygieneFinding>
     */
    public function audit(array $entries, \DateTimeImmutable $now, array $allowlist = []): array
    {
        $findings = [];

        foreach ($entries as $entry) {
            if ($entry->rotationIntervalSeconds !== null && $entry->lastRotatedAt !== null) {
                $ageSeconds = $now->getTimestamp() - $entry->lastRotatedAt->getTimestamp();
                if ($ageSeconds > $entry->rotationIntervalSeconds) {
                    $findings[] = new SecretHygieneFinding(
                        secretId: $entry->id,
                        kind: 'stale',
                        detail: sprintf(
                            'Secret "%s" is %d seconds overdue for rotation (interval: %ds).',
                            $entry->id,
                            $ageSeconds - $entry->rotationIntervalSeconds,
                            $entry->rotationIntervalSeconds,
                        ),
                    );
                }
            }

            if ($entry->rawValue !== null) {
                foreach (self::LEAK_PATTERNS as $patternName => $regex) {
                    if (in_array($patternName, $allowlist, true)) {
                        continue;
                    }

                    if (preg_match($regex, $entry->rawValue) === 1) {
                        $findings[] = new SecretHygieneFinding(
                            secretId: $entry->id,
                            kind: 'leaked',
                            detail: sprintf(
                                'Secret "%s" matches leak pattern "%s".',
                                $entry->id,
                                $patternName,
                            ),
                        );
                        break;
                    }
                }
            }
        }

        return $findings;
    }
}
