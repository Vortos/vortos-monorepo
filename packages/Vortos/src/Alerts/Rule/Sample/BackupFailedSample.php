<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Sample;

/** Observed result for `backup_failed`. */
final readonly class BackupFailedSample implements SampleInterface
{
    public function __construct(
        public bool $failed,
        public string $detail = '',
    ) {}
}
