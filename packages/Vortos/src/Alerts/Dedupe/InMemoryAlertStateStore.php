<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

/** Unit/test default — no persistence across processes. */
final class InMemoryAlertStateStore implements AlertStateStoreInterface
{
    /** @var array<string, AlertState> */
    private array $states = [];

    public function get(string $fingerprint): ?AlertState
    {
        return $this->states[$fingerprint] ?? null;
    }

    public function save(AlertState $state): void
    {
        $this->states[$state->fingerprint] = $state;
    }
}
