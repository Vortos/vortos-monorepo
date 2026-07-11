<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * JSON flag change-history: timeline, field-level diff between any two versions, and
 * revert to a prior snapshot. The revert rebuilds the target {@see FeatureFlag} from the
 * audit snapshot and routes it through the single write boundary so it is itself audited.
 */
#[AsController]
final class FlagHistoryController
{
    public function __construct(
        private readonly FlagAuditLogRepositoryInterface $auditLog,
        private readonly FlagStorageInterface $storage,
        private readonly FlagWriteService $writeService,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly FlagScopeContext $scopeContext,
        private readonly ChangeRequestInterceptorInterface $changeRequestInterceptor,
    ) {}

    #[Route('/api/management/v1/flags/{name}/history', name: 'vortos.management.flags.history', methods: ['GET'])]
    public function list(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $limit   = max(1, min(200, (int) $request->query->get('limit', '50')));
        $entries = $this->auditLog->findByFlag($name, $limit);

        return $this->response->ok(array_map($this->summarize(...), $entries));
    }

    #[Route('/api/management/v1/flags/{name}/history/diff', name: 'vortos.management.flags.history.diff', methods: ['GET'])]
    public function diff(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $idA = (string) $request->query->get('a', '');
        $idB = (string) $request->query->get('b', '');
        $entries = $this->auditLog->findByFlag($name, 200);
        $a = $this->find($entries, $idA);
        $b = $this->find($entries, $idB);

        if ($a === null || $b === null) {
            throw new NotFoundException('One or both audit entries not found.');
        }

        $dataA = $a->data['snapshot'] ?? $a->data;
        $dataB = $b->data['snapshot'] ?? $b->data;

        return $this->response->ok([
            'a'       => ['eventId' => $a->eventId, 'eventType' => $a->eventType, 'occurredAt' => $a->occurredAt, 'snapshot' => $dataA],
            'b'       => ['eventId' => $b->eventId, 'eventType' => $b->eventType, 'occurredAt' => $b->occurredAt, 'snapshot' => $dataB],
            'changed' => $this->changedKeys($dataA, $dataB),
        ]);
    }

    #[Route('/api/management/v1/flags/{name}/history/revert', name: 'vortos.management.flags.history.revert', methods: ['POST'])]
    public function revert(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $body   = json_decode((string) $request->getContent(), true);
        $body   = is_array($body) ? $body : [];
        $env    = (string) ($body['env'] ?? 'production');
        $this->scopeContext->withEnvironment($env);
        $eventId = (string) ($body['eventId'] ?? '');
        $reason  = trim((string) ($body['reason'] ?? ''));

        if (strlen($reason) < 10) {
            throw new ForbiddenException('A reason of at least 10 characters is required for a revert.');
        }
        if ($this->changeRequestInterceptor->isProtected($name, $env)) {
            return new JsonResponse(['message' => 'Change request required for this environment.'], 202);
        }

        $entry = $this->find($this->auditLog->findByFlag($name, 200), $eventId);
        if ($entry === null) {
            throw new NotFoundException('Audit entry not found.');
        }
        $current = $this->storage->findByName($name);
        if ($current === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $snapshot = $entry->data['snapshot'] ?? $entry->data;
        $target   = FeatureFlag::fromArray(array_merge($current->toArray(), $snapshot));
        $this->writeService->revertTo($target, $actor->id(), "Revert via console: {$reason}");

        return $this->response->ok(['reverted' => true, 'to_event' => $eventId]);
    }

    /** @param FlagAuditEntry[] $entries */
    private function find(array $entries, string $eventId): ?FlagAuditEntry
    {
        foreach ($entries as $e) {
            if ($e->eventId === $eventId) {
                return $e;
            }
        }
        return null;
    }

    private function summarize(FlagAuditEntry $e): array
    {
        return [
            'eventId'     => $e->eventId,
            'eventType'   => $e->eventType,
            'actorId'     => $e->actorId,
            'reason'      => $e->reason,
            'occurredAt'  => $e->occurredAt,
            'environment' => $e->environment,
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return string[]
     */
    private function changedKeys(array $a, array $b): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }
        sort($changed);
        return $changed;
    }
}
