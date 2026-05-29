<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Report;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Report\Report;
use Vortos\Paddle\Report\ReportService;
use Vortos\Paddle\ValueObject\PaddleReportId;
use Vortos\Paddle\ValueObject\ReportType;

final class ReportServiceTest extends TestCase
{
    private function makeSdkReport(string $id = 'rep_test', string $status = 'pending'): \Paddle\SDK\Entities\Report
    {
        return \Paddle\SDK\Entities\Report::from([
            'id'         => $id,
            'status'     => $status,
            'rows'       => null,
            'type'       => 'transactions',
            'filters'    => [],
            'expires_at' => null,
            'created_at' => '2024-01-01T00:00:00.000000Z',
            'updated_at' => '2024-01-02T00:00:00.000000Z',
        ]);
    }

    public function test_create_report_returns_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkReport('rep_abc'));

        $service = new ReportService($client);
        $id      = $service->createReport(new \Vortos\Paddle\Report\Operation\CreateReportRequest(
            type: ReportType::Transactions,
        ));

        $this->assertInstanceOf(PaddleReportId::class, $id);
        $this->assertSame('rep_abc', $id->value);
    }

    public function test_get_report_returns_mapped_report(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkReport('rep_xyz', 'ready'));

        $service = new ReportService($client);
        $report  = $service->getReport(PaddleReportId::of('rep_xyz'));

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('rep_xyz', $report->id->value);
        $this->assertSame(\Vortos\Paddle\ValueObject\ReportStatus::Ready, $report->status);
        $this->assertTrue($report->isReady());
    }

    public function test_pending_report_is_not_ready(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkReport('rep_pending', 'pending'));

        $service = new ReportService($client);
        $report  = $service->getReport(PaddleReportId::of('rep_pending'));

        $this->assertFalse($report->isReady());
    }

    public function test_get_download_url_returns_string(): void
    {
        $sdkCsv = \Paddle\SDK\Entities\ReportCSV::from(['url' => 'https://reports.paddle.com/rep_xyz.csv']);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdkCsv);

        $service = new ReportService($client);
        $url     = $service->getDownloadUrl(PaddleReportId::of('rep_xyz'));

        $this->assertSame('https://reports.paddle.com/rep_xyz.csv', $url);
    }
}
