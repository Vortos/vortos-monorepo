<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Webhook\SsrfGuard;
use Vortos\FeatureFlags\Webhook\WebhookStorageInterface;
use Vortos\FeatureFlags\Webhook\WebhookSubscription;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\HttpException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * Outbound webhook subscriptions: register HTTPS endpoints to receive signed flag-event
 * notifications, list them, and revoke them. The signing secret is returned exactly once
 * at creation. URLs are SSRF-validated (HTTPS only, no internal IPs). Admin-gated.
 */
#[AsController]
final class WebhookManagementController
{
    public function __construct(
        private readonly WebhookStorageInterface $storage,
        private readonly SsrfGuard $ssrf,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
    ) {}

    #[Route('/api/management/v1/webhooks', name: 'vortos.management.webhooks.list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->guard();
        return $this->response->list(array_map(static fn(WebhookSubscription $w) => $w->toArray(), $this->storage->findActive()), null, 0);
    }

    #[Route('/api/management/v1/webhooks', name: 'vortos.management.webhooks.create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->guard();

        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $url  = (string) ($body['url'] ?? '');
        /** @var string[] $eventTypes */
        $eventTypes = array_values(array_filter((array) ($body['eventTypes'] ?? []), 'is_string'));

        if ($url === '' || $eventTypes === []) {
            throw new HttpException(422, 'url and at least one eventType are required.');
        }

        $check = $this->ssrf->validate($url);
        if (($check['safe'] ?? false) !== true) {
            throw new HttpException(422, 'URL rejected: ' . ($check['reason'] ?? 'unsafe'));
        }

        $rawSecret = bin2hex(random_bytes(24));
        $sub = new WebhookSubscription(
            id:          Uuid::v7()->toRfc4122(),
            url:         $url,
            secretHash:  hash('sha256', $rawSecret),
            eventTypes:  $eventTypes,
            projectId:   isset($body['projectId']) ? (string) $body['projectId'] : null,
            environment: isset($body['environment']) ? (string) $body['environment'] : null,
            active:      true,
        );
        $this->storage->save($sub, $rawSecret);

        return $this->response->created(['secret' => $rawSecret, 'webhook' => $sub->toArray()]);
    }

    #[Route('/api/management/v1/webhooks/{id}', name: 'vortos.management.webhooks.delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->guard();

        if ($this->storage->findById($id) === null) {
            throw new NotFoundException('Webhook not found.');
        }
        $this->storage->delete($id);

        return $this->response->ok(['deleted' => true]);
    }

    private function guard(): void
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());
    }
}
