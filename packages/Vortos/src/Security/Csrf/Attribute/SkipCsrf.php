<?php

declare(strict_types=1);

namespace Vortos\Security\Csrf\Attribute;

use Attribute;

/**
 * Exempts a controller class or action from CSRF token validation.
 *
 * Use for stateless JWT endpoints (where CSRF is redundant) and webhook
 * receivers (which use request signing instead).
 *
 * Example:
 *
 *   #[SkipCsrf]
 *   class StripeWebhookController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SkipCsrf {}
