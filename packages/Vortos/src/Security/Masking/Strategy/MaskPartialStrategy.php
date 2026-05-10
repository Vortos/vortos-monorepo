<?php

declare(strict_types=1);

namespace Vortos\Security\Masking\Strategy;

use Vortos\Security\Contract\MaskingStrategyInterface;

/**
 * Shows a partial value with the middle masked.
 *
 * Email:  john.doe@example.com  →  jo***@ex***.com
 * Phone:  +1-555-867-5309       →  +1-***-***-5309
 * Other:  secret123             →  se***23
 */
final class MaskPartialStrategy implements MaskingStrategyInterface
{
    public function mask(string $value): string
    {
        if ($value === '') {
            return '***';
        }

        // Email masking
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->maskEmail($value);
        }

        // Generic: show first 2 and last 2 chars
        $len = mb_strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return mb_substr($value, 0, 2) . str_repeat('*', $len - 4) . mb_substr($value, -2);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        $localLen    = mb_strlen($local);
        $maskedLocal = $localLen <= 2
            ? str_repeat('*', $localLen)
            : mb_substr($local, 0, 2) . '***';

        $parts        = explode('.', $domain, 2);
        $domainName   = $parts[0];
        $tld          = isset($parts[1]) ? '.' . $parts[1] : '';
        $maskedDomain = mb_substr($domainName, 0, 2) . '***' . $tld;

        return $maskedLocal . '@' . $maskedDomain;
    }
}
