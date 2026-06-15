<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Exception;

use Vortos\Auth\FeatureAccess\Contract\FeatureAccessDecision;

final class FeatureAccessDeniedException extends \RuntimeException
{
    public function __construct(
        public readonly string $feature,
        public readonly FeatureAccessDecision $decision,
    ) {
        parent::__construct(sprintf('Feature access denied for "%s".', $feature));
    }
}
