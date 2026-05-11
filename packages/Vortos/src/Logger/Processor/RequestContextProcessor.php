<?php

declare(strict_types=1);

namespace Vortos\Logger\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds bounded request, tenant, and user context when an HTTP request exists.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    /**
     * @param object|null $requestStack Symfony RequestStack when available.
     * @param object|null $currentUserProvider Vortos CurrentUserProvider when available.
     */
    public function __construct(
        private readonly ?object $requestStack = null,
        private readonly ?object $currentUserProvider = null,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $request = $this->requestStack !== null && method_exists($this->requestStack, 'getCurrentRequest')
            ? $this->requestStack->getCurrentRequest()
            : null;

        if ($request !== null) {
            $extra['http.request.method'] = $request->getMethod();
            $extra['url.path'] = $request->getPathInfo();
            $extra['client.address'] = (string) $request->getClientIp();
            $extra['user_agent.original'] = (string) $request->headers->get('user-agent', '');

            $tenantId = $request->headers->get('x-tenant-id')
                ?? $request->attributes->get('tenant_id')
                ?? $request->attributes->get('tenantId');

            if (is_scalar($tenantId) && (string) $tenantId !== '') {
                $extra['tenant.id'] = (string) $tenantId;
            }
        }

        if ($this->currentUserProvider !== null && method_exists($this->currentUserProvider, 'get')) {
            try {
                $user = $this->currentUserProvider->get();
                if (is_object($user) && method_exists($user, 'id')) {
                    $extra['user.id'] = (string) $user->id();
                }
            } catch (\Throwable) {
                // Logging must not change request/auth behavior.
            }
        }

        return $record->with(extra: $extra);
    }
}
