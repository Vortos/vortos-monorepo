<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Export;

enum ExportFormat: string
{
    case Ndjson = 'ndjson';
    case Csv    = 'csv';

    public function contentType(): string
    {
        return match ($this) {
            self::Ndjson => 'application/x-ndjson',
            self::Csv    => 'text/csv',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
