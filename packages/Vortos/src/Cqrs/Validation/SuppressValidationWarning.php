<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Validation;

use Attribute;

/**
 * Suppresses the ValidationPass compile-time warning on a specific property.
 *
 * Use when a string property intentionally has no Length or NotBlank constraint —
 * for example, a markdown body stored externally where length is enforced
 * by the storage layer, not the command.
 *
 * Example:
 *   #[SuppressValidationWarning]
 *   public string $markdownBody = '';
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class SuppressValidationWarning
{
}
