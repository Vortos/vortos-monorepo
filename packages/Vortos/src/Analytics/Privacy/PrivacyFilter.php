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
 *
 * One narrow exception: {@see ReservedProperties} — the framework's own control
 * properties (flag attribution, and the flag-exposure event's `flag`/`variant`) survive
 * the allowlist, because they are framework-authored, carry no user data, and an app
 * forgetting to allowlist them produced a bridge that emitted empty values rather than
 * an error. The consent gate and the PII redactor still apply to them.
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

        $shaped = $this->redactor->redact(
            $this->filterKeeping(
                $this->eventAllowlist,
                $event->properties,
                ReservedProperties::forEvent($event->name),
            ),
        );

        return new AnalyticsEvent($event->distinctId, $event->name, $shaped, $event->timestamp, $event->groups);
    }

    public function applyIdentity(IdentitySet $identity): ?IdentitySet
    {
        if (!$this->consentGate->allows($identity->distinctId)) {
            return null;
        }

        $shaped = $this->redactor->redact(
            $this->filterKeeping($this->traitAllowlist, $identity->traits, ReservedProperties::global()),
        );

        return new IdentitySet($identity->distinctId, $shaped);
    }

    public function applyGroup(GroupAssociation $group): ?GroupAssociation
    {
        if (!$this->consentGate->allows($group->distinctId)) {
            return null;
        }

        $shaped = $this->redactor->redact(
            $this->filterKeeping($this->traitAllowlist, $group->traits, ReservedProperties::global()),
        );

        return new GroupAssociation($group->distinctId, $group->groupType, $group->groupKey, $shaped);
    }

    /**
     * Apply the allowlist, then re-admit the reserved keys that were present on the way
     * in. Reserved keys are re-admitted from the *original* bag rather than exempted
     * before filtering, so this can only ever return keys the caller actually supplied.
     *
     * @param array<string,mixed> $properties
     * @param list<string>        $reserved
     * @return array<string,mixed>
     */
    private function filterKeeping(PropertyAllowlist $allowlist, array $properties, array $reserved): array
    {
        $shaped = $allowlist->filter($properties);

        foreach ($reserved as $key) {
            if (array_key_exists($key, $properties)) {
                $shaped[$key] = $properties[$key];
            }
        }

        return $shaped;
    }
}
