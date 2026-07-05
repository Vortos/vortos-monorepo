<?php

declare(strict_types=1);

namespace Vortos\Http\Exception;

/**
 * Thrown at container-compile time when two controllers register the same route name.
 *
 * A duplicate route name is a programming/wiring error, not a runtime condition — resolving it by
 * "last controller wins" is non-deterministic (the winner depends on unstable tagged-service
 * iteration order), which is how two packages both registering vortos.health.ready produced a
 * route that resolved to different controllers in byte-identical containers. Failing fast at compile
 * makes the collision impossible to ship.
 */
final class DuplicateRouteNameException extends \LogicException
{
    public function __construct(
        public readonly string $routeName,
        public readonly string $firstController,
        public readonly string $secondController,
    ) {
        parent::__construct(sprintf(
            'Duplicate route name "%s": already registered by %s, redeclared by %s. '
            . 'Route names must be unique across all controllers tagged "vortos.api.controller"; '
            . 'rename one route or retire the duplicate controller.',
            $routeName,
            $firstController,
            $secondController,
        ));
    }
}
