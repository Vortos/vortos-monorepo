<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Compliance\Export\AuditExportFilter;
use Vortos\FeatureFlags\Compliance\Export\AuditExportService;
use Vortos\FeatureFlags\Compliance\Export\ExportFormat;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\HttpException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

#[AsController]
final class AuditExportController
{
    public function __construct(
        private readonly AuditExportService $exporter,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
    ) {}

    /**
     * POST /api/management/v1/audit/export
     *
     * Returns the signed manifest. The client must poll or stream the body in chunks.
     * For large exports, prefer the CLI command which streams directly to disk.
     *
     * Request body (JSON):
     *   format      string  ndjson|csv  (default: ndjson)
     *   flagName    string  optional
     *   environment string  optional
     *   projectId   string  optional
     *   actorId     string  optional
     *   from        string  ISO-8601 optional
     *   to          string  ISO-8601 optional
     */
    #[Route('/api/management/v1/audit/export', name: 'vortos.management.audit.export', methods: ['POST'])]
    public function export(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $body = json_decode((string) $request->getContent(), true);

        if (!is_array($body)) {
            throw new HttpException(400, 'Request body must be JSON.');
        }

        $formatStr = strtolower((string) ($body['format'] ?? 'ndjson'));
        $format    = ExportFormat::tryFrom($formatStr);

        if ($format === null) {
            throw new HttpException(422, 'Unsupported format. Use "ndjson" or "csv".');
        }

        $from = isset($body['from']) ? $this->parseDate((string) $body['from']) : null;
        $to   = isset($body['to'])   ? $this->parseDate((string) $body['to'])   : null;

        try {
            $filter = new AuditExportFilter(
                flagName:    isset($body['flagName']) ? (string) $body['flagName'] : null,
                environment: isset($body['environment']) ? (string) $body['environment'] : null,
                projectId:   isset($body['projectId']) ? (string) $body['projectId'] : null,
                actorId:     isset($body['actorId']) ? (string) $body['actorId'] : null,
                from:        $from,
                to:          $to,
            );
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        $chunks   = [];
        $manifest = $this->exporter->export($filter, $format, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        return $this->response->ok([
            'manifest' => json_decode($manifest->toJson(), true),
            'data'     => base64_encode(implode('', $chunks)),
            'encoding' => 'base64',
            'mimeType' => $format->contentType(),
        ]);
    }

    /**
     * POST /api/management/v1/audit/export/verify
     *
     * Verifies that a previously exported file matches its manifest.
     */
    #[Route('/api/management/v1/audit/export/verify', name: 'vortos.management.audit.export.verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $body = json_decode((string) $request->getContent(), true);

        if (!is_array($body) || !isset($body['manifest'], $body['format'])) {
            throw new HttpException(400, 'Request body must contain "manifest" and "format".');
        }

        $format   = ExportFormat::tryFrom(strtolower((string) $body['format']));
        if ($format === null) {
            throw new HttpException(422, 'Unsupported format.');
        }

        $m = $body['manifest'];

        try {
            $manifest = new \Vortos\FeatureFlags\Compliance\Export\SignedManifest(
                schemaVersion:     (int) ($m['schemaVersion'] ?? 1),
                format:            $format,
                rowCount:          (int) ($m['rowCount'] ?? 0),
                rangeFrom:         isset($m['rangeFrom']) ? new \DateTimeImmutable($m['rangeFrom']) : null,
                rangeTo:           isset($m['rangeTo']) ? new \DateTimeImmutable($m['rangeTo']) : null,
                generatedAt:       new \DateTimeImmutable($m['generatedAt']),
                generatorIdentity: (string) ($m['generatorIdentity'] ?? ''),
                contentHash:       (string) ($m['contentHash'] ?? ''),
                signature:         (string) ($m['signature'] ?? ''),
            );
        } catch (\Throwable $e) {
            throw new HttpException(422, 'Malformed manifest: ' . $e->getMessage());
        }

        $filter = new AuditExportFilter();
        $valid  = $this->exporter->verify($filter, $format, $manifest);

        return $this->response->ok(['verified' => $valid]);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
