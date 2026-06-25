<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

use Vortos\Analytics\Event\DistinctId;

/**
 * Resolves a {@see ConsentDecision} for a distinctId and only lets `Granted`
 * through. `Denied`/`Unknown` are dropped and counted — the count is the only
 * artifact of a denied event, never the event's content.
 */
final class ConsentGate
{
    private int $droppedCount = 0;

    public function __construct(private readonly ConsentResolverInterface $resolver) {}

    public function allows(DistinctId $distinctId): bool
    {
        $decision = $this->resolver->resolve($distinctId);
        if ($decision === ConsentDecision::Granted) {
            return true;
        }

        $this->droppedCount++;

        return false;
    }

    public function droppedCount(): int
    {
        return $this->droppedCount;
    }
}
