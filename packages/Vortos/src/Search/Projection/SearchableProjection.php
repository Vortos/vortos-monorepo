<?php

declare(strict_types=1);

namespace Vortos\Search\Projection;

/**
 * THE auto-searchable seam.
 *
 * An application makes an aggregate searchable by implementing this once and tagging it (or
 * relying on autoconfiguration — any service implementing this interface is registered with
 * the {@see \Vortos\Search\Projection\SearchProjectorRegistry} by the framework's compiler
 * pass). Adding a new searchable TYPE is exactly one class; new INSTANCES need no work at all
 * because they arrive through the same domain events the projector already subscribes to.
 *
 * The framework never learns what an "application" is — it only ever receives an event object
 * and gets back an upsert/delete/nothing.
 */
interface SearchableProjection
{
    /**
     * Fully-qualified event class names this projector reacts to. The registry dispatches an
     * event to a projector only when the event is an instance of one of these, so unrelated
     * traffic costs a single instanceof check.
     *
     * @return list<class-string>
     */
    public function subscribesTo(): array;

    /**
     * Translate one domain event into an index mutation.
     *
     * Resolve the deep-link and the permission/owner here — you already hold the aggregate.
     * Return null to ignore an event you subscribed to but don't want to index (e.g. a status
     * transition that leaves the document unchanged).
     */
    public function project(object $event): SearchUpsert|SearchDelete|null;
}
