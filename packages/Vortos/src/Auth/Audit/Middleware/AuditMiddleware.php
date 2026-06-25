<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\AuditFailureMode;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;
use Vortos\Auth\Audit\Integrity\AuthAuditHashChain;
use Vortos\Auth\Audit\Integrity\ChainStateStoreInterface;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Http\JsonResponse;

#[AsMiddleware(order: MiddlewareOrder::AUTHORIZATION)]
final class AuditMiddleware implements MiddlewareInterface
{
    private const SENSITIVE_PARAMS = ['password', 'token', 'secret', 'key', 'authorization', 'credential', 'api_key', 'apikey'];
    private const METADATA_VALUE_MAX_LENGTH = 1024;
    private const RESOURCE_ID_MAX_LENGTH = 255;
    private const METADATA_KEY_PATTERN = '/^[a-zA-Z0-9_.\-]{1,128}$/';
    private const CONTROL_CHARS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * @param array<string, list<array{action: string, include: list<string>}>> $routeMap
     */
    public function __construct(
        private CurrentUserProvider      $currentUser,
        private ?AuditStoreInterface     $store,
        private array                    $routeMap,
        private AuditFailureMode         $failureMode = AuditFailureMode::FailClosed,
        private IpResolverInterface      $ipResolver = new \Vortos\Http\IpResolver\RemoteAddrIpResolver(),
        private ?LoggerInterface         $logger = null,
        private ?AuthAuditHashChain      $hashChain = null,
        private ?ChainStateStoreInterface $chainStateStore = null,
        private string                   $auditHmacKey = '',
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) {
            return $response;
        }

        if ($this->store === null) {
            $this->logger?->error('audit.store_unavailable', ['reason' => 'no store configured']);
            if ($this->failureMode === AuditFailureMode::FailClosed) {
                return new JsonResponse(
                    ['error' => 'Audit Unavailable', 'message' => 'Audit logging service is not configured.'],
                    Response::HTTP_SERVICE_UNAVAILABLE,
                );
            }
            return $response;
        }

        $identity = $this->currentUser->get();
        $userId = $identity->isAuthenticated() ? $identity->id() : 'anonymous';
        $statusCode = $response->getStatusCode();

        foreach ($this->routeMap[$controller] as $rule) {
            $metadata = ['_status' => $statusCode];

            foreach ($rule['include'] as $param) {
                if (in_array(strtolower($param), self::SENSITIVE_PARAMS, true)) {
                    continue;
                }

                if (preg_match(self::METADATA_KEY_PATTERN, $param) !== 1) {
                    continue;
                }

                $value = $request->attributes->get($param) ?? $request->query->get($param);
                if ($value === null) {
                    continue;
                }

                $metadata[$param] = $this->sanitizeMetadataValue($value);
            }

            $resourceId = $this->sanitizeResourceId($request->attributes->get('id'));

            $entry = AuditEntry::create(
                userId: $userId,
                action: $rule['action'],
                resourceId: $resourceId,
                ipAddress: $this->ipResolver->resolve($request),
                userAgent: $this->sanitizeString($request->headers->get('User-Agent', '')),
                metadata: $metadata,
            );

            if ($this->hashChain !== null && $this->chainStateStore !== null && $this->auditHmacKey !== '') {
                try {
                    $entry = $this->chainStateStore->appendChained(
                        fn (int $sequence, string $prevHash) => $this->hashChain->chain($entry, $sequence, $prevHash, $this->auditHmacKey),
                    );
                } catch (\Throwable $e) {
                    $this->logger?->error('audit.chain_failed', [
                        'action' => $rule['action'],
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);

                    if ($this->failureMode === AuditFailureMode::FailClosed) {
                        return new JsonResponse(
                            ['error' => 'Audit Unavailable', 'message' => 'Audit integrity chain failed.'],
                            Response::HTTP_SERVICE_UNAVAILABLE,
                        );
                    }
                }
            }

            try {
                $this->store->record($entry);
            } catch (\Throwable $e) {
                $this->logger?->error('audit.record_failed', [
                    'action' => $rule['action'],
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                if ($this->failureMode === AuditFailureMode::FailClosed) {
                    return new JsonResponse(
                        ['error' => 'Audit Unavailable', 'message' => 'Audit logging failed.'],
                        Response::HTTP_SERVICE_UNAVAILABLE,
                    );
                }
            }
        }

        return $response;
    }

    private function sanitizeMetadataValue(mixed $value): string|int|float|bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = $this->sanitizeString($value);
            if (strlen($value) > self::METADATA_VALUE_MAX_LENGTH) {
                return substr($value, 0, self::METADATA_VALUE_MAX_LENGTH) . '…';
            }
            return $value;
        }

        return '[redacted:non-scalar]';
    }

    private function sanitizeString(string $value): string
    {
        return preg_replace(self::CONTROL_CHARS, '', $value) ?? $value;
    }

    private function sanitizeResourceId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $str = (string) $value;
        $str = $this->sanitizeString($str);

        if (strlen($str) > self::RESOURCE_ID_MAX_LENGTH) {
            return substr($str, 0, self::RESOURCE_ID_MAX_LENGTH);
        }

        return $str;
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) {
            return explode('::', $controller)[0];
        }
        if (is_array($controller)) {
            return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        }
        if (is_object($controller)) {
            return get_class($controller);
        }
        return null;
    }
}
