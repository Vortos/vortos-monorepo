<?php

declare(strict_types=1);

namespace Vortos\Security\Masking\Attribute;

use Attribute;
use Vortos\Security\Masking\Strategy\MaskPartialStrategy;

/**
 * Marks a DTO or entity property as sensitive PII.
 *
 * When DataMaskingProcessor is enabled, Monolog context arrays are scanned for
 * keys matching properties with this attribute. Matched values are replaced with
 * the configured masking strategy before the record is written to any handler.
 *
 * @param string $strategy FQCN of a MaskingStrategyInterface. Defaults to MaskPartialStrategy.
 *
 * Example:
 *
 *   final class RegisterUserDto
 *   {
 *       #[Sensitive]
 *       public string $email;
 *
 *       #[Sensitive(MaskAllStrategy::class)]
 *       public string $password;
 *   }
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Sensitive
{
    public function __construct(
        public readonly string $strategy = MaskPartialStrategy::class,
    ) {}
}
