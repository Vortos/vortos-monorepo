<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

/**
 * Resolves an {@see ErrorSinkInterface} driver by its stable key. Backed by a
 * compile-time-collected ServiceLocator; only installed drivers register, with zero
 * runtime reflection (Golden Rule #1).
 */
final class ErrorSinkRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('observability_error_sink', $drivers);
    }

    public function sink(string $key): ErrorSinkInterface
    {
        /** @var ErrorSinkInterface */
        return $this->get($key);
    }

    /** @return list<ErrorSinkInterface> */
    public function allSinks(): array
    {
        $sinks = [];
        foreach ($this->keys() as $key) {
            $sinks[] = $this->sink($key);
        }

        return $sinks;
    }
}
