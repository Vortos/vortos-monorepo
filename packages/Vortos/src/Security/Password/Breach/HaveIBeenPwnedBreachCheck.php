<?php

declare(strict_types=1);

namespace Vortos\Security\Password\Breach;

use Vortos\Security\Contract\PasswordRuleInterface;
use Vortos\Security\Password\PasswordPolicyViolation;

/**
 * Checks passwords against the HaveIBeenPwned Pwned Passwords API.
 *
 * ## Privacy guarantee (k-anonymity)
 *
 * Only the first 5 characters of the SHA-1 hash are sent to the API.
 * The full hash (and therefore the password) never leaves this process.
 * HIBP returns all hashes sharing that prefix — we check locally.
 *
 * @see https://haveibeenpwned.com/API/v3#PwnedPasswords
 *
 * ## Requirements
 *
 * ext-curl (PHP built-in) or a compatible HTTP client.
 * Network access to api.pwnedpasswords.com on port 443.
 */
final class HaveIBeenPwnedBreachCheck implements PasswordRuleInterface
{
    private const API_URL = 'https://api.pwnedpasswords.com/range/';
    private const TIMEOUT = 3; // seconds

    public function validate(string $password): ?PasswordPolicyViolation
    {
        $hash   = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $results = $this->fetchRange($prefix);
        if ($results === null) {
            // API unavailable — fail open (do not block password change)
            return null;
        }

        if ($this->isBreached($suffix, $results)) {
            return new PasswordPolicyViolation(
                'breached_password',
                'This password has appeared in a known data breach. Please choose a different password.',
            );
        }

        return null;
    }

    private function fetchRange(string $prefix): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init(self::API_URL . $prefix);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => 'vortos-security/1.0',
            CURLOPT_HTTPHEADER     => ['Add-Padding: true'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        return (string) $response;
    }

    private function isBreached(string $suffix, string $responseBody): bool
    {
        foreach (explode("\n", $responseBody) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$hashSuffix] = explode(':', $line, 2);
            if (strtoupper(trim($hashSuffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }
}
