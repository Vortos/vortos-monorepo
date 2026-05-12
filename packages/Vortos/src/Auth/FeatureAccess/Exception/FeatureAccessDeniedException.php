<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Exception;

final class FeatureAccessDeniedException extends \RuntimeException
{
    public function __construct(
        public readonly string $feature,
        public readonly bool $paymentRequired,
    ) {
        parent::__construct(sprintf('Feature access denied for "%s".', $feature));
    }
}
