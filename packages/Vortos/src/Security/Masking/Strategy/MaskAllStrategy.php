<?php

declare(strict_types=1);

namespace Vortos\Security\Masking\Strategy;

use Vortos\Security\Contract\MaskingStrategyInterface;

/** Replaces the entire value with '***'. Use for passwords, tokens, and API keys. */
final class MaskAllStrategy implements MaskingStrategyInterface
{
    public function mask(string $value): string
    {
        return '***';
    }
}
