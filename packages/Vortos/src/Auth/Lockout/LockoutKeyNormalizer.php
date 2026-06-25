<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout;

final class LockoutKeyNormalizer
{
    public function normalize(string $type, string $value): string
    {
        $value = trim($value);

        if ($type === 'email') {
            $value = mb_strtolower($value, 'UTF-8');
        }

        return hash('sha256', $type . ':' . $value);
    }
}
