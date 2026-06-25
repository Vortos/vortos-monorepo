<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class IacEngineRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('iac-engine', $drivers);
    }

    public function engine(string $key): IacEngineInterface
    {
        /** @var IacEngineInterface */
        return $this->get($key);
    }
}
