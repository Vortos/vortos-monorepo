<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Contract;

use Symfony\Component\HttpFoundation\Response;

/**
 * The outcome of a feature-access evaluation.
 *
 * The policy returns this instead of a bool so that the *reason* for a denial —
 * which only the policy knows — decides the HTTP status, rather than a flag
 * hard-coded on the route attribute.
 *
 *   Allowed         → request proceeds
 *   Forbidden       → 403, the identity's plan does not include the feature
 *   PaymentRequired → 402, the identity was entitled but the subscription lapsed
 */
enum FeatureAccessDecision
{
    case Allowed;
    case Forbidden;
    case PaymentRequired;

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }

    /**
     * Precedence when multiple policies disagree: the more restrictive denial wins.
     * Forbidden > PaymentRequired > Allowed — never invite payment for something
     * the plan can never include anyway.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Allowed         => 0,
            self::PaymentRequired => 1,
            self::Forbidden       => 2,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Allowed         => Response::HTTP_OK,
            self::Forbidden       => Response::HTTP_FORBIDDEN,
            self::PaymentRequired => Response::HTTP_PAYMENT_REQUIRED,
        };
    }
}
