<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

final class InMemoryAckStore implements AckStoreInterface
{
    /** @var array<string, Acknowledgement> */
    private array $acks = [];

    public function record(Acknowledgement $ack): void
    {
        $this->acks[$ack->fingerprint] = $ack;
    }

    public function find(string $fingerprint): ?Acknowledgement
    {
        return $this->acks[$fingerprint] ?? null;
    }
}
