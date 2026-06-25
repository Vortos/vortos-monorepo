<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class CanaryAnalyzerRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('canary-analyzer', $drivers);
    }

    public function analyzer(string $key): CanaryAnalyzerInterface
    {
        /** @var CanaryAnalyzerInterface */
        return $this->get($key);
    }
}
