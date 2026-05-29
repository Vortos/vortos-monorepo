<?php

declare(strict_types=1);

namespace Vortos\Paddle\Report;

use Vortos\Paddle\ValueObject\PaddleReportId;
use Vortos\Paddle\ValueObject\ReportStatus;
use Vortos\Paddle\ValueObject\ReportType;

final class Report
{
    public function __construct(
        public readonly PaddleReportId      $id,
        public readonly ReportStatus        $status,
        public readonly ReportType          $type,
        public readonly ?int                $rows,
        public readonly ?\DateTimeImmutable  $expiresAt,
        public readonly \DateTimeImmutable   $createdAt,
        public readonly \DateTimeImmutable   $updatedAt,
    ) {}

    public function isReady(): bool
    {
        return $this->status === ReportStatus::Ready;
    }

    public static function fromSdk(\Paddle\SDK\Entities\Report $sdk): self
    {
        return new self(
            id:        PaddleReportId::of($sdk->id),
            status:    ReportStatus::from($sdk->status->getValue()),
            type:      ReportType::from($sdk->type->getValue()),
            rows:      $sdk->rows,
            expiresAt: $sdk->expiresAt !== null
                           ? \DateTimeImmutable::createFromInterface($sdk->expiresAt)
                           : null,
            createdAt: \DateTimeImmutable::createFromInterface($sdk->createdAt),
            updatedAt: \DateTimeImmutable::createFromInterface($sdk->updatedAt),
        );
    }
}
