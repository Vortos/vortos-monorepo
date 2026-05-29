<?php

declare(strict_types=1);

namespace Vortos\Paddle\Report\Operation;

use Vortos\Paddle\ValueObject\ReportType;

final class CreateReportRequest
{
    public function __construct(
        public readonly ReportType $type,
        public readonly array      $filters = [],
    ) {}
}
