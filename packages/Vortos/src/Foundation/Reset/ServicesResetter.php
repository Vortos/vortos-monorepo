<?php
declare(strict_types=1);

namespace Vortos\Foundation\Reset;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class ServicesResetter
{
    /**
     * @param string[] $serviceIds
     */
    public function __construct(
        private ContainerInterface $services,
        private array $serviceIds = [],
    ) {}

    public function reset(): void
    {
        foreach ($this->serviceIds as $serviceId) {
            if (!$this->services->has($serviceId)) {
                continue;
            }

            $service = $this->services->get($serviceId);

            if (!$service instanceof ResetInterface) {
                continue;
            }

            $service->reset();
        }
    }
}
