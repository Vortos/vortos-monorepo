<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditFacets;
use Vortos\Audit\Query\AuditPage;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Storage\AuditReaderInterface;
use Vortos\AuditAdmin\Http\AuditWindow;
use Vortos\AuditAdmin\Http\Controller\OrgAuditController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditController;
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;

/**
 * The date-range filter on the audit list endpoints. Both controllers built their AuditQuery
 * without ever reading `from`/`to`, so a caller's date range was accepted with a 200 and
 * silently ignored — wrong rows rather than an error, and the export path (which did read the
 * window) disagreed with the list it was exported from.
 */
final class AuditListWindowTest extends TestCase
{
    public function test_org_list_forwards_the_requested_window_to_the_query(): void
    {
        $reader     = $this->reader();
        $controller = new OrgAuditController($this->service($reader), $this->tenant('org-7'));

        $controller(new Request(query: ['from' => '2026-08-01T00:00:00+00:00', 'to' => '2026-08-19T23:59:59+00:00']));

        self::assertNotNull($reader->seen);
        self::assertSame('2026-08-01T00:00:00+00:00', $reader->seen->from?->format('Y-m-d\TH:i:sP'));
        self::assertSame('2026-08-19T23:59:59+00:00', $reader->seen->to?->format('Y-m-d\TH:i:sP'));
        self::assertSame('org-7', $reader->seen->tenantId, 'the window must not disturb tenant scoping');
    }

    public function test_platform_list_forwards_the_requested_window_to_the_query(): void
    {
        $reader     = $this->reader();
        $controller = new PlatformAuditController($this->service($reader));

        $controller(new Request(query: ['from' => '2026-08-01T00:00:00+00:00']));

        self::assertSame('2026-08-01T00:00:00+00:00', $reader->seen?->from?->format('Y-m-d\TH:i:sP'));
        self::assertNull($reader->seen?->to);
    }

    public function test_an_absent_window_stays_unbounded(): void
    {
        $reader     = $this->reader();
        $controller = new OrgAuditController($this->service($reader), $this->tenant('org-7'));

        $controller(new Request(query: []));

        self::assertNull($reader->seen?->from);
        self::assertNull($reader->seen?->to);
    }

    /**
     * A list request is re-issued on every keystroke, so a half-typed date must degrade to
     * "no bound" rather than 500 the page.
     */
    public function test_an_unparseable_date_is_ignored_rather_than_fatal(): void
    {
        $window = AuditWindow::fromRequest(new Request(query: ['from' => 'not-a-date', 'to' => '2026-08']));

        self::assertNull($window->from);
        self::assertNotNull($window->to, 'a partial but valid date is still a bound');
    }

    private function reader(): object
    {
        return new class implements AuditQueryInterface {
            public ?AuditQuery $seen = null;
            public function page(AuditQuery $query): AuditPage
            {
                $this->seen = $query;
                return new AuditPage([], null);
            }
            public function facets(AuditQuery $query): AuditFacets
            {
                return new AuditFacets([], [], []);
            }
        };
    }

    private function service(object $query): AuditAdminService
    {
        $reader = new class implements AuditReaderInterface {
            public function chainTail(string $chainKey): ?array { return null; }
            public function readChain(string $chainKey, int $afterSequence, int $limit): array { return []; }
        };

        return new AuditAdminService($query, $reader, new AuditChainVerifier(new AuditHashChain()));
    }

    private function tenant(string $orgId): TenantContext
    {
        $context = new TenantContext();
        $context->set($orgId);
        return $context;
    }
}
