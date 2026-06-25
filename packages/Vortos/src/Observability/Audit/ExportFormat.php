<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

enum ExportFormat: string
{
    case Ndjson = 'ndjson';
    case Csv = 'csv';
}
