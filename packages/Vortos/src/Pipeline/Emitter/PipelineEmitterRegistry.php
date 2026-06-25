<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Emitter;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;
use Vortos\Pipeline\Exception\UnknownEmitterException;

final class PipelineEmitterRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('pipeline_emitter', $drivers);
    }

    public function emitter(string $key): PipelineEmitterInterface
    {
        if (!$this->has($key)) {
            throw UnknownEmitterException::forKey($key, $this->keys());
        }

        /** @var PipelineEmitterInterface */
        return $this->get($key);
    }
}
