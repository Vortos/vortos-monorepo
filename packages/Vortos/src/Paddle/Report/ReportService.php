<?php

declare(strict_types=1);

namespace Vortos\Paddle\Report;

use Paddle\SDK\Entities\Report\ReportType;
use Paddle\SDK\Resources\Reports\Operations\CreateReport;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Report\Operation\CreateReportRequest;
use Vortos\Paddle\ValueObject\PaddleReportId;

final class ReportService
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function createReport(CreateReportRequest $request): PaddleReportId
    {
        $sdkReport = $this->client->call(
            fn() => $this->client->sdk()->reports->create(
                new CreateReport(type: ReportType::from($request->type->value))
            )
        );

        return PaddleReportId::of($sdkReport->id);
    }

    public function getReport(PaddleReportId $id): Report
    {
        $sdk = $this->client->call(
            fn() => $this->client->sdk()->reports->get($id->value)
        );

        return Report::fromSdk($sdk);
    }

    public function getDownloadUrl(PaddleReportId $id): string
    {
        $csv = $this->client->call(
            fn() => $this->client->sdk()->reports->getReportCsv($id->value)
        );

        return $csv->url;
    }
}
