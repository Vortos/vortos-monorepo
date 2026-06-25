<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

use InvalidArgumentException;

/**
 * One step of a {@see SyntheticJourney} — a real HTTP call against the app, not a
 * synthetic-monitor-internal abstraction. `extractAs`/`extractJsonPath` let a later
 * step reuse a value (e.g. an auth token) produced by an earlier step.
 */
final readonly class JourneyStep
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        public string $method,
        public string $pathTemplate,
        public int $expectStatus,
        public ?string $bodyContains = null,
        public ?string $extractAs = null,
        public ?string $extractJsonPath = null,
    ) {
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'JourneyStep method must be one of [%s], got "%s".',
                implode(', ', self::ALLOWED_METHODS),
                $method,
            ));
        }
        if ($pathTemplate === '' || $pathTemplate[0] !== '/') {
            throw new InvalidArgumentException('JourneyStep pathTemplate must be a non-empty path starting with "/".');
        }
        if ($expectStatus < 100 || $expectStatus > 599) {
            throw new InvalidArgumentException('JourneyStep expectStatus must be a valid HTTP status code (100-599).');
        }
        if ($bodyContains !== null && $bodyContains === '') {
            throw new InvalidArgumentException('JourneyStep bodyContains, if set, must not be empty.');
        }
        if ($extractAs !== null && ($extractAs === '' || $extractJsonPath === null)) {
            throw new InvalidArgumentException('JourneyStep extractAs requires a non-empty extractJsonPath.');
        }
    }

    public function assertsBodyInvariant(): bool
    {
        return $this->bodyContains !== null;
    }
}
