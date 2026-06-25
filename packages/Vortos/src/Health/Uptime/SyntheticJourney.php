<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

use InvalidArgumentException;

/**
 * A declarative multi-step user journey the external monitor asserts — deliberately
 * NOT "200 /" (§3, build-plan #3). At least two steps (e.g. login then fetch a real
 * authenticated resource) and at least one body-invariant assertion are required, so
 * a journey that degrades to a bare liveness ping fails construction, not review.
 */
final readonly class SyntheticJourney
{
    /** @param list<JourneyStep> $steps */
    public function __construct(
        public string $name,
        public array $steps,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('SyntheticJourney name must not be empty.');
        }
        if (count($steps) < 2) {
            throw new InvalidArgumentException(
                'SyntheticJourney must have at least 2 steps — a single-step check degrades to "200 /", '
                . 'which defeats the point of an external synthetic prober.',
            );
        }

        $hasBodyAssertion = false;
        foreach ($steps as $step) {
            if ($step->assertsBodyInvariant()) {
                $hasBodyAssertion = true;
            }
        }

        if (!$hasBodyAssertion) {
            throw new InvalidArgumentException(
                'SyntheticJourney must assert at least one body invariant (bodyContains) — '
                . 'a journey that only checks status codes can pass while the real user journey is broken.',
            );
        }
    }
}
