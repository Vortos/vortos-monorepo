<?php

declare(strict_types=1);

namespace Vortos\Search\Projection;

/**
 * Routes a domain event to the {@see SearchableProjection}s that care about it, and applies the
 * resulting upsert/delete to the index writer. Built once at compile time from every tagged
 * projector (see the discovery compiler pass), so adding a searchable type never touches this
 * class — the whole point of the auto-searchable seam.
 *
 * An event class is matched against each projector's {@see SearchableProjection::subscribesTo()}
 * via instanceof, so a projector also fires for subclasses of the events it declares.
 */
final class SearchProjectorRegistry
{
    /** @var list<SearchableProjection> */
    private array $projectors;

    /** @param iterable<SearchableProjection> $projectors */
    public function __construct(iterable $projectors = [])
    {
        $this->projectors = $projectors instanceof \Traversable
            ? iterator_to_array($projectors, false)
            : array_values($projectors);
    }

    /**
     * Every projection outcome for $event, in projector registration order. Pure — it performs
     * no writes, so it is trivially unit-testable; the handler applies the outcomes.
     *
     * @return list<SearchUpsert|SearchDelete>
     */
    public function project(object $event): array
    {
        $outcomes = [];
        foreach ($this->projectors as $projector) {
            if (!$this->subscribes($projector, $event)) {
                continue;
            }
            $outcome = $projector->project($event);
            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        return $outcomes;
    }

    /** True if any registered projector subscribes to this event's class. */
    public function handles(object $event): bool
    {
        foreach ($this->projectors as $projector) {
            if ($this->subscribes($projector, $event)) {
                return true;
            }
        }

        return false;
    }

    private function subscribes(SearchableProjection $projector, object $event): bool
    {
        foreach ($projector->subscribesTo() as $class) {
            if ($event instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
