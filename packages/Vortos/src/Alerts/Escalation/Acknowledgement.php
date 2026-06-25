<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Acknowledgement
{
    public function __construct(
        public string $fingerprint,
        public int $tier,
        public string $ackedBy,
        public DateTimeImmutable $ackedAt,
    ) {
        if ($fingerprint === '' || $ackedBy === '') {
            throw new InvalidArgumentException('Acknowledgement fingerprint/ackedBy must not be empty.');
        }
    }
}
