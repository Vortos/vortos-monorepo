<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

interface AckStoreInterface
{
    public function record(Acknowledgement $ack): void;

    /** The most recent acknowledgement for $fingerprint, or null if never acked. */
    public function find(string $fingerprint): ?Acknowledgement;
}
