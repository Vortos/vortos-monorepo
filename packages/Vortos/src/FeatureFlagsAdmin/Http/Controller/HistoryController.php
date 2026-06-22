<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class HistoryController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly FlagAuditLogRepositoryInterface $auditLog,
        private readonly FlagStorageInterface $storage,
        private readonly FlagWriteService $writeService,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ChangeRequestInterceptorInterface $changeRequestInterceptor,
        private readonly FlagScopeContext $scopeContext,
    ) {}

    #[Route('/admin/flags/history', name: 'vortos.admin.flags.history', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authz->requirePermission('flags.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flagName = $request->query->get('flag', '');
        $limit = min(200, max(10, (int) $request->query->get('limit', '50')));

        $entries = $flagName !== ''
            ? $this->auditLog->findByFlag($flagName, $limit)
            : [];

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('history/_timeline.html.twig', [
                'entries' => $entries,
                'flag_name' => $flagName,
                'prefix' => '/admin/flags',
            ]);
        }

        return $this->renderer->render('history/index.html.twig', [
            'entries' => $entries,
            'flag_name' => $flagName,
            'active_nav' => 'history',
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/history/{flagName}/diff/{eventIdA}/{eventIdB}', name: 'vortos.admin.flags.history.diff', methods: ['GET'])]
    public function diff(Request $request, string $flagName, string $eventIdA, string $eventIdB): Response
    {
        $this->authz->requirePermission('flags.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $entries = $this->auditLog->findByFlag($flagName, 200);

        $entryA = null;
        $entryB = null;
        foreach ($entries as $entry) {
            if ($entry->eventId === $eventIdA) {
                $entryA = $entry;
            }
            if ($entry->eventId === $eventIdB) {
                $entryB = $entry;
            }
        }

        if ($entryA === null || $entryB === null) {
            throw new NotFoundException('Audit entry not found.');
        }

        $diffLines = $this->computeDiff($entryA->data, $entryB->data);

        return $this->renderer->renderFragment('history/_diff.html.twig', [
            'entry_a' => $entryA,
            'entry_b' => $entryB,
            'diff_lines' => $diffLines,
            'flag_name' => $flagName,
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/history/{flagName}/revert/{eventId}', name: 'vortos.admin.flags.history.revert', methods: ['POST'])]
    public function revert(Request $request, string $flagName, string $eventId): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        $reason = trim($request->request->get('reason', ''));
        if (strlen($reason) < 10) {
            throw new ForbiddenException('A reason of at least 10 characters is required for revert.');
        }

        if ($this->changeRequestInterceptor->isProtected($flagName, $env)) {
            throw new ForbiddenException('This flag/environment requires a change request for modifications.');
        }

        $entries = $this->auditLog->findByFlag($flagName, 200);
        $targetEntry = null;
        foreach ($entries as $entry) {
            if ($entry->eventId === $eventId) {
                $targetEntry = $entry;
                break;
            }
        }

        if ($targetEntry === null) {
            throw new NotFoundException('Audit entry not found.');
        }

        $snapshotData = $targetEntry->data['snapshot'] ?? $targetEntry->data;
        $currentFlag = $this->storage->findByName($flagName);
        if ($currentFlag === null) {
            throw new NotFoundException("Flag '{$flagName}' not found.");
        }

        $targetFlag = FeatureFlag::fromArray(array_merge($currentFlag->toArray(), $snapshotData));

        $actorId = $this->currentUser->get()->id();
        $this->writeService->revertTo($targetFlag, $actorId, "Revert via admin UI: {$reason}");

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('history/_revert_success.html.twig', [
                'flag_name' => $flagName,
                'event_id' => $eventId,
            ]);
        }

        return new Response('', 302, ['Location' => "/admin/flags/history?flag={$flagName}"]);
    }

    /** @return list<array{type: string, content: string}> */
    private function computeDiff(array $dataA, array $dataB): array
    {
        $jsonA = json_encode($dataA, JSON_PRETTY_PRINT | JSON_HEX_TAG);
        $jsonB = json_encode($dataB, JSON_PRETTY_PRINT | JSON_HEX_TAG);

        $linesA = explode("\n", $jsonA);
        $linesB = explode("\n", $jsonB);

        $diff = [];
        $maxLines = max(count($linesA), count($linesB));

        for ($i = 0; $i < $maxLines; $i++) {
            $lineA = $linesA[$i] ?? null;
            $lineB = $linesB[$i] ?? null;

            if ($lineA === $lineB) {
                if ($lineA !== null) {
                    $diff[] = ['type' => 'same', 'content' => $lineA];
                }
            } else {
                if ($lineA !== null) {
                    $diff[] = ['type' => 'remove', 'content' => $lineA];
                }
                if ($lineB !== null) {
                    $diff[] = ['type' => 'add', 'content' => $lineB];
                }
            }
        }

        return $diff;
    }
}
