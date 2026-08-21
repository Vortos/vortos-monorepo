<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Export\AuditExportFilter;

final class AuditExportFilterTest extends TestCase
{
    public function test_impersonated_only_round_trips_through_the_persisted_job_payload(): void
    {
        // The filter is stored as JSON on the job and rebuilt hours later in another process;
        // a field that does not survive that round trip exports the wrong set of rows.
        $filter = new AuditExportFilter(action: 'user.updated', impersonatedOnly: true);

        self::assertTrue($filter->toArray()['impersonated']);
        self::assertTrue(AuditExportFilter::fromArray($filter->toArray())->impersonatedOnly);
    }

    public function test_impersonated_only_reaches_the_rebuilt_query(): void
    {
        $query = (new AuditExportFilter(impersonatedOnly: true))->toAuditQuery(Scope::Tenant, 'org-1');

        self::assertTrue($query->impersonatedOnly);
    }

    public function test_default_filter_is_not_impersonation_scoped(): void
    {
        self::assertFalse((new AuditExportFilter())->impersonatedOnly);
        self::assertFalse(AuditExportFilter::fromArray([])->impersonatedOnly);
        self::assertFalse((new AuditExportFilter())->toAuditQuery(Scope::Platform, null)->impersonatedOnly);
    }
}
