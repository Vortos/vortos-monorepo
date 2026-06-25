<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Policy;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class PlanPolicyRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('iac-policy', $drivers);
    }

    public function policy(string $key): PlanPolicyInterface
    {
        /** @var PlanPolicyInterface */
        return $this->get($key);
    }
}
