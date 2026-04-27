<?php
declare(strict_types=1);

namespace Vortos\Auth\TwoFactor\Attribute;

/**
 * Requires 2FA verification to access this controller or method.
 * Redirects to challenge URL if not verified.
 *
 * #[Requires2FA]
 * public function deleteAccount(): Response { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Requires2FA {}
