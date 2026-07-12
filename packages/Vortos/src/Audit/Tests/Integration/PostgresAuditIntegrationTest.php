<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Event\AuditTarget;
use Vortos\Audit\Export\AuditExporter;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\Dbal\DbalAuditQueryReader;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\SavedView\AuditSavedView;
use Vortos\Audit\SavedView\Dbal\DbalAuditSavedViewStore;
use Vortos\Audit\Search\PostgresFtsSearchIndex;
use Vortos\Audit\Storage\Dbal\DbalAuditStore;
use Vortos\Audit\Storage\Dbal\Lock\PgAdvisoryChainLock;
use Vortos\Audit\Storage\Dbal\Postgres\AuditTenantGuc;
use Vortos\Audit\Storage\Dbal\Postgres\PostgresAuditExtrasInstaller;

/**
 * End-to-end against a REAL Postgres (booted via Docker). Exercises the storage/verify/query
 * path plus the F2 additions — FTS search, facets, saved views, and RLS tenant isolation —
 * the parts unit tests with a mock connection can't prove. Skips cleanly when Docker isn't
 * available, so it never blocks CI or a publish.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class PostgresAuditIntegrationTest extends TestCase
{
    private static ?string $container = null;
    private static ?Connection $conn = null;
    private const HMAC = 'integration-test-key';

    public static function setUpBeforeClass(): void
    {
        if (self::sh('docker info') === null) {
            self::markTestSkipped('Docker is not available.');
        }

        $port = random_int(35000, 45000);
        $name = 'vortos-audit-it-' . getmypid();
        $run  = self::sh(sprintf(
            'docker run -d --rm --name %s -e POSTGRES_PASSWORD=pw -e POSTGRES_DB=audit -p %d:5432 postgres:16-alpine',
            $name,
            $port,
        ));
        if ($run === null) {
            self::markTestSkipped('Could not start a Postgres container.');
        }
        self::$container = $name;

        // Wait for readiness.
        $conn = null;
        for ($i = 0; $i < 30; $i++) {
            try {
                $conn = DriverManager::getConnection([
                    'driver'   => 'pdo_pgsql',
                    'host'     => '127.0.0.1',
                    'port'     => $port,
                    'user'     => 'postgres',
                    'password' => 'pw',
                    'dbname'   => 'audit',
                ]);
                $conn->executeQuery('SELECT 1');
                break;
            } catch (\Throwable) {
                $conn = null;
                usleep(500_000);
            }
        }
        if ($conn === null) {
            self::tearDownAfterClass();
            self::markTestSkipped('Postgres did not become ready in time.');
        }
        self::$conn = $conn;
        self::migrate($conn);
    }

    public static function tearDownAfterClass(): void
    {
        self::$conn?->close();
        self::$conn = null;
        if (self::$container !== null) {
            self::sh('docker rm -f ' . self::$container);
            self::$container = null;
        }
    }

    public function test_append_and_verify_chain(): void
    {
        $store = $this->store();
        $store->record(AuditEvent::create(Scope::Platform, null, AuditActor::system(), 'flag.published'));
        $store->record(AuditEvent::create(Scope::Platform, null, AuditActor::system(), 'flag.retired'));

        $result = $this->admin()->verifyChain('platform');
        self::assertTrue($result->valid);
        self::assertSame(2, $result->verifiedCount);
    }

    public function test_tampering_a_row_breaks_verification(): void
    {
        $store = $this->store();
        $store->record(AuditEvent::create(Scope::Tenant, 'tamper-org', AuditActor::system(), 'member.invited'));

        self::$conn->executeStatement(
            "UPDATE vortos_audit_events SET action = 'member.removed' WHERE chain_key = 'tenant:tamper-org'",
        );

        self::assertFalse($this->admin()->verifyChain('tenant:tamper-org')->valid);
    }

    public function test_action_prefix_and_facets(): void
    {
        $store = $this->store();
        $org   = 'facet-org';
        foreach (['payment.captured', 'payment.refunded', 'member.invited'] as $action) {
            $store->record(AuditEvent::create(
                Scope::Tenant, $org, AuditActor::system(), $action, null,
                str_starts_with($action, 'payment.refunded') ? Sensitivity::High : Sensitivity::Normal,
            ));
        }

        $reader = $this->reader();
        $page   = $reader->page(new AuditQuery(scope: Scope::Tenant, tenantId: $org, actionPrefix: 'payment.'));
        self::assertCount(2, $page->records);

        $facets = $reader->facets(new AuditQuery(scope: Scope::Tenant, tenantId: $org));
        self::assertSame(1, $facets->byAction['payment.captured']);
        self::assertSame(1, $facets->bySensitivity['high']);
    }

    public function test_postgres_full_text_search(): void
    {
        $installer = new PostgresAuditExtrasInstaller(self::$conn);
        $installer->installFtsIndex(); // idempotent; also proves the GIN DDL is valid

        $store = $this->store();
        $org   = 'fts-org';
        $store->record(AuditEvent::create(
            Scope::Tenant, $org, AuditActor::user('u1', 'Ada Lovelace'), 'member.invited',
            new AuditTarget('member', 'm1', 'Grace Hopper'),
        ));
        $store->record(AuditEvent::create(Scope::Tenant, $org, AuditActor::system(), 'flag.published'));

        $reader = new DbalAuditQueryReader(self::$conn, 'vortos_audit_events', new PostgresFtsSearchIndex());
        $page   = $reader->page(new AuditQuery(scope: Scope::Tenant, tenantId: $org, search: 'Grace'));

        self::assertCount(1, $page->records);
        self::assertSame('member.invited', $page->records[0]->event->action);
    }

    public function test_saved_view_round_trip_is_scope_bound(): void
    {
        $store = new DbalAuditSavedViewStore(self::$conn);
        $mine  = AuditSavedView::create('sv-org', 'owner-1', 'Refunds', ['actionPrefix' => 'payment.refunded']);
        $other = AuditSavedView::create('sv-org', 'owner-2', 'Theirs', []);
        $store->save($mine);
        $store->save($other);

        $list = $store->listFor('sv-org', 'owner-1');
        self::assertCount(1, $list);
        self::assertSame('Refunds', $list[0]->name);

        self::assertFalse($store->delete($mine->id, 'sv-org', 'owner-2'), 'cannot delete another owner\'s view');
        self::assertTrue($store->delete($mine->id, 'sv-org', 'owner-1'));
    }

    public function test_rls_confines_a_tenant_session_to_its_own_rows(): void
    {
        $store = $this->store();
        $store->record(AuditEvent::create(Scope::Tenant, 'rls-a', AuditActor::system(), 'member.invited'));
        $store->record(AuditEvent::create(Scope::Tenant, 'rls-b', AuditActor::system(), 'member.invited'));

        (new PostgresAuditExtrasInstaller(self::$conn))->enableRls();

        // A non-superuser role so RLS actually applies (superusers bypass it).
        self::$conn->executeStatement("DROP ROLE IF EXISTS audit_app");
        self::$conn->executeStatement("CREATE ROLE audit_app LOGIN PASSWORD 'pw2' NOSUPERUSER");
        self::$conn->executeStatement('GRANT SELECT ON vortos_audit_events TO audit_app');

        $appConn = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => '127.0.0.1',
            'port' => (int) self::$conn->getParams()['port'],
            'user' => 'audit_app', 'password' => 'pw2', 'dbname' => 'audit',
        ]);

        $guc = new AuditTenantGuc($appConn);
        $guc->scopeToTenant('rls-a');

        $countA = (int) $appConn->fetchOne("SELECT COUNT(*) FROM vortos_audit_events WHERE tenant_id = 'rls-a'");
        $countB = (int) $appConn->fetchOne("SELECT COUNT(*) FROM vortos_audit_events WHERE tenant_id = 'rls-b'");
        self::assertGreaterThanOrEqual(1, $countA, 'tenant A sees its own rows');
        self::assertSame(0, $countB, 'RLS hides tenant B rows from a session scoped to tenant A');

        $appConn->close();
        // Restore an unscoped state for any later assertions in this process.
        (new PostgresAuditExtrasInstaller(self::$conn))->disableRls();
    }

    // --- helpers ---------------------------------------------------------

    private function store(): DbalAuditStore
    {
        return new DbalAuditStore(self::$conn, new AuditHashChain(), self::HMAC, 'vortos_audit_events', new PgAdvisoryChainLock());
    }

    private function reader(): DbalAuditQueryReader
    {
        return new DbalAuditQueryReader(self::$conn, 'vortos_audit_events');
    }

    private function admin(): AuditAdminService
    {
        $store    = $this->store();
        $chain    = new AuditHashChain();
        $exporter = new AuditExporter($this->reader(), new StoredAuditEventSerializer(), $chain, self::HMAC);

        return new AuditAdminService($this->reader(), $store, new AuditChainVerifier($chain), $exporter, self::HMAC);
    }

    private static function migrate(Connection $conn): void
    {
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE vortos_audit_events (
                id varchar(36) PRIMARY KEY,
                scope varchar(16) NOT NULL,
                tenant_id varchar(255) NULL,
                actor text NOT NULL,
                action varchar(128) NOT NULL,
                target text NULL,
                sensitivity varchar(16) NOT NULL,
                outcome varchar(16) NOT NULL,
                source text NOT NULL,
                context text NOT NULL,
                occurred_at varchar(40) NOT NULL,
                chain_key varchar(255) NOT NULL,
                sequence bigint NOT NULL,
                prev_hash char(64) NOT NULL,
                content_hash char(64) NOT NULL,
                signature char(64) NOT NULL,
                CONSTRAINT uq_audit_chain_seq UNIQUE (chain_key, sequence)
            )
        SQL);
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE vortos_audit_saved_views (
                id varchar(36) PRIMARY KEY,
                tenant_id varchar(255) NULL,
                owner_id varchar(255) NOT NULL,
                name varchar(255) NOT NULL,
                filters text NOT NULL,
                created_at varchar(40) NOT NULL
            )
        SQL);
    }

    private static function sh(string $cmd): ?string
    {
        $out = [];
        $code = 0;
        exec($cmd . ' 2>/dev/null', $out, $code);

        return $code === 0 ? implode("\n", $out) : null;
    }
}
