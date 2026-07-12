<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Audit\Http\AuditTenantRlsMiddleware;
use Vortos\Audit\Storage\Dbal\Postgres\AuditTenantGuc;
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;

final class AuditTenantRlsMiddlewareTest extends TestCase
{
    public function test_sets_the_guc_to_the_request_tenant(): void
    {
        $tenant = new TenantContext();
        $tenant->set('org-42');

        [$conn, $captured] = $this->capturingConnection();
        $mw = new AuditTenantRlsMiddleware(new AuditTenantGuc($conn), $tenant);

        $response = $mw->handle(new Request(), static fn (Request $r): \Symfony\Component\HttpFoundation\Response
            => new \Symfony\Component\HttpFoundation\Response('ok'));

        self::assertSame('ok', $response->getContent());
        self::assertSame('org-42', $captured->value, 'the request tenant is pushed into app.current_tenant');
    }

    public function test_clears_the_guc_for_a_platform_request(): void
    {
        $tenant = new TenantContext(); // no tenant set → platform/system scope

        [$conn, $captured] = $this->capturingConnection();
        $mw = new AuditTenantRlsMiddleware(new AuditTenantGuc($conn), $tenant);

        $mw->handle(new Request(), static fn (Request $r): \Symfony\Component\HttpFoundation\Response
            => new \Symfony\Component\HttpFoundation\Response('ok'));

        self::assertSame('', $captured->value, 'a platform request clears the GUC (policy stays permissive)');
    }

    /**
     * @return array{0: Connection, 1: object}
     */
    private function capturingConnection(): array
    {
        // AuditTenantGuc only issues set_config when the platform is Postgres; force that and
        // capture the bound value.
        $captured = new class { public ?string $value = null; };

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $conn = $this->createMock(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) use ($captured): int {
            $captured->value = $params['v'] ?? null;
            return 0;
        });

        return [$conn, $captured];
    }
}
