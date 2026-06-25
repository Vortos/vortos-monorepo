<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;

/**
 * Composes consent gate -> allowlist -> PII redactor, in that order, and is applied
 * *before* any driver sees an event. Returns null when an event/identity/group is
 * fully suppressed (consent denied) — the caller (decorator) then no-ops.
 *
 * Event properties and identify/group traits use independent allowlists: traits are
 * the most PII-prone surface and default to an empty (deny-all) allowlist, while
 * events may carry a small, app-configured safe set.
 */
final readonly class PrivacyFilter
{
    public function __construct(
        private ConsentGate $consentGate,
        private PropertyAllowlist $eventAllowlist,
        private PropertyAllowlist $traitAllowlist,
        private PiiRedactor $redactor,
    ) {}

    public function apply(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (!$this->consentGate->allows($event->distinctId)) {
            return null;
        }

        $shaped = $this->redactor->redact($this->eventAllowlist->filter($event->properties));

        return new AnalyticsEvent($event->distinctId, $event->name, $shaped, $event->timestamp, $event->groups);
    }

    public function applyIdentity(IdentitySet $identity): ?IdentitySet
    {
        if (!$this->consentGate->allows($identity->distinctId)) {
            return null;
        }

        $shaped = $this->redactor->redact($this->traitAllowlist->filter($identity->traits));

        return new IdentitySet($identity->distinctId, $shaped);
    }

    public function applyGroup(GroupAssociation $group): ?GroupAssociation
    {
        if (!$this->consentGate->allows($group->distinctId)) {
            return null;
        }

        $shaped = $this->redactor->redact($this->traitAllowlist->filter($group->traits));

        return new GroupAssociation($group->distinctId, $group->groupType, $group->groupKey, $shaped);
    }
}
