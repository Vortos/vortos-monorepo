<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Audit\Export\AuditExporter;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditPage;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\Storage\AuditReaderInterface;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditVerifyController;
use Vortos\Http\Request;

final class PlatformAuditVerifyControllerTest extends TestCase
{
    public function test_verifies_the_requested_chain_and_returns_its_result(): void
    {
        // An empty chain verifies clean (0 records) — enough to prove the controller wires
        // the request's chainKey through the admin service and serialises the result.
        $reader = new class implements AuditReaderInterface {
            public ?string $askedFor = null;
            public function chainTail(string $chainKey): ?array { return null; }
            public function readChain(string $chainKey, int $afterSequence, int $limit): array
            {
                $this->askedFor = $chainKey;
                return [];
            }
        };

        $service = $this->service($reader);
        $controller = new PlatformAuditVerifyController($service);

        $request = new Request(content: json_encode(['chainKey' => 'tenant:org-5']));
        $response = $controller($request);

        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['valid']);
        self::assertSame(0, $body['verifiedCount']);
        self::assertSame('tenant:org-5', $reader->askedFor, 'the request chainKey is forwarded to the verifier');
    }

    public function test_defaults_to_the_platform_chain_when_no_key_given(): void
    {
        $reader = new class implements AuditReaderInterface {
            public ?string $askedFor = null;
            public function chainTail(string $chainKey): ?array { return null; }
            public function readChain(string $chainKey, int $afterSequence, int $limit): array
            {
                $this->askedFor = $chainKey;
                return [];
            }
        };

        $controller = new PlatformAuditVerifyController($this->service($reader));
        $controller(new Request(content: '{}'));

        self::assertSame('platform', $reader->askedFor);
    }

    private function service(AuditReaderInterface $reader): AuditAdminService
    {
        $query = new class implements AuditQueryInterface {
            public function page(AuditQuery $query): AuditPage { return new AuditPage([], null); }
            public function facets(AuditQuery $query): \Vortos\Audit\Query\AuditFacets { return new \Vortos\Audit\Query\AuditFacets([], [], []); }
        };
        $chain    = new AuditHashChain();
        $exporter = new AuditExporter($query, new StoredAuditEventSerializer(), $chain);

        return new AuditAdminService($query, $reader, new AuditChainVerifier($chain), $exporter);
    }
}
