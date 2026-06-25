<?php

declare(strict_types=1);

namespace Vortos\Alerts\Routing;

use InvalidArgumentException;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;

/**
 * The declared `severity (× source × tenant) → channel keys` contract (§3.4). The
 * matrix is the contract; channels are swappable per §10 — never hard-code a channel
 * into routing logic.
 *
 * Precedence (most specific wins): tenant override → source override → default.
 */
final readonly class RoutingMatrix
{
    /**
     * @param array<string, list<string>>                    $bySeverity      Severity::value => channel keys
     * @param array<string, array<string, list<string>>>      $sourceOverrides AlertSource::value => Severity::value => channel keys
     * @param array<string, array<string, list<string>>>      $tenantOverrides tenantId => Severity::value => channel keys
     */
    public function __construct(
        public array $bySeverity,
        public array $sourceOverrides = [],
        public array $tenantOverrides = [],
    ) {
        foreach ($bySeverity as $severity => $channels) {
            if (Severity::tryFrom($severity) === null) {
                throw new InvalidArgumentException(sprintf('RoutingMatrix: unknown severity key "%s".', $severity));
            }
            if ($channels === []) {
                throw new InvalidArgumentException(sprintf('RoutingMatrix: severity "%s" must route to at least one channel.', $severity));
            }
        }
    }

    /** The default contract: info/warning → chat, critical → page + chat mirror. */
    public static function default(): self
    {
        return new self([
            Severity::Info->value => ['eng-chat'],
            Severity::Warning->value => ['eng-chat'],
            Severity::Critical->value => ['oncall-page', 'eng-chat'],
        ]);
    }

    /** @return list<string> */
    public function channelsFor(Severity $severity, AlertSource $source, ?string $tenantId): array
    {
        if ($tenantId !== null && isset($this->tenantOverrides[$tenantId][$severity->value])) {
            return $this->tenantOverrides[$tenantId][$severity->value];
        }

        if (isset($this->sourceOverrides[$source->value][$severity->value])) {
            return $this->sourceOverrides[$source->value][$severity->value];
        }

        return $this->bySeverity[$severity->value] ?? [];
    }
}
