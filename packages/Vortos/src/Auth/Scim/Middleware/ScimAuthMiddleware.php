<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Middleware;

use Vortos\Auth\Scim\Token\ScimTokenRecord;
use Vortos\Auth\Scim\Token\ScimTokenService;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates SCIM 2.0 requests via Bearer token.
 *
 * Runs at AUTH (order 700). For tagged SCIM routes only:
 *  1. Extracts Bearer token from Authorization header
 *  2. Validates via ScimTokenService (SHA-256 hash lookup, constant-time)
 *  3. Checks IP allowlist on the token record
 *  4. Checks required scopes for the HTTP method + resource type
 *  5. Enforces Content-Type on mutating requests (RFC 7644)
 *  6. Establishes tenant context from the token
 *
 * Fail-closed: any missing/invalid/expired/revoked token → 401; wrong scope/IP → 403.
 */
#[AsMiddleware(order: MiddlewareOrder::AUTH)]
final class ScimAuthMiddleware implements MiddlewareInterface
{
    private const BEARER_PREFIX = 'Bearer ';

    private const SCOPE_MAP = [
        'GET'    => 'read',
        'POST'   => 'write',
        'PUT'    => 'write',
        'PATCH'  => 'write',
        'DELETE' => 'write',
    ];

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH'];

    private const ALLOWED_CONTENT_TYPES = [
        'application/scim+json',
        'application/json',
    ];

    /**
     * @param array<string, array{resource: string}> $routeMap Controller key → resource type ('users'|'groups'|'discovery')
     */
    public function __construct(
        private readonly ScimTokenService    $tokenService,
        private readonly TenantContext       $tenantContext,
        private readonly array               $routeMap,
        private readonly IpResolverInterface $ipResolver = new \Vortos\Http\IpResolver\RemoteAddrIpResolver(),
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $route = $this->resolveRoute($request->attributes->get('_controller'));
        if ($route === null) {
            return $next($request);
        }

        $resource = $route['resource'];

        // Discovery endpoints are public (RFC 7644 §4)
        if ($resource === 'discovery') {
            return $next($request);
        }

        // 1. Extract Bearer token
        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, self::BEARER_PREFIX)) {
            return $this->scimError(401, 'Bearer token required.', ['WWW-Authenticate' => 'Bearer']);
        }

        $rawToken = substr($authHeader, strlen(self::BEARER_PREFIX));
        if ($rawToken === '' || $rawToken === false) {
            return $this->scimError(401, 'Bearer token required.', ['WWW-Authenticate' => 'Bearer']);
        }

        // 2. Validate token
        $record = $this->tokenService->validate($rawToken);
        if ($record === null) {
            return $this->scimError(401, 'Invalid, expired, or revoked SCIM token.', ['WWW-Authenticate' => 'Bearer']);
        }

        // 3. IP allowlist
        $clientIp = $this->ipResolver->resolve($request);
        if (!$this->isIpAllowed($clientIp, $record)) {
            return $this->scimError(403, 'Request origin not in token IP allowlist.');
        }

        // 4. Scope check
        $requiredScope = $this->requiredScope($request->getMethod(), $resource);
        if ($requiredScope !== null && !$record->hasScope($requiredScope)) {
            return $this->scimError(403, "Insufficient scope. Required: {$requiredScope}");
        }

        // 5. Content-Type enforcement on mutating requests (RFC 7644 §3.1)
        if (in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            $contentType = $request->headers->get('Content-Type', '');
            $mediaType = strtolower(trim(explode(';', $contentType)[0]));
            if (!in_array($mediaType, self::ALLOWED_CONTENT_TYPES, true)) {
                return $this->scimError(
                    415,
                    'Content-Type must be application/scim+json or application/json.',
                );
            }
        }

        // 6. Establish tenant context
        $this->tenantContext->set($record->tenantId);

        // 7. Stamp request
        $request->attributes->set('_scim_token_record', $record);
        $request->attributes->set('_scim_tenant_id', $record->tenantId);

        return $next($request);
    }

    private function resolveRoute(mixed $controller): ?array
    {
        if (is_string($controller)) {
            $parts = explode('::', $controller);
            // Check method-level first, then class-level
            if (count($parts) === 2 && isset($this->routeMap[$controller])) {
                return $this->routeMap[$controller];
            }
            return $this->routeMap[$parts[0]] ?? null;
        }

        if (is_array($controller)) {
            $class = is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
            $full = isset($controller[1]) ? $class . '::' . $controller[1] : $class;
            return $this->routeMap[$full] ?? $this->routeMap[$class] ?? null;
        }

        if (is_object($controller)) {
            return $this->routeMap[get_class($controller)] ?? null;
        }

        return null;
    }

    private function requiredScope(string $method, string $resource): ?string
    {
        $action = self::SCOPE_MAP[$method] ?? null;
        if ($action === null) {
            return null;
        }

        return "scim:{$resource}:{$action}";
    }

    private function isIpAllowed(string $clientIp, ScimTokenRecord $record): bool
    {
        if ($record->allowedCidrs === []) {
            return true;
        }

        if ($clientIp === '') {
            return false;
        }

        foreach ($record->allowedCidrs as $cidr) {
            if ($this->ipMatchesCidr($clientIp, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }

        // Build mask
        $mask = str_repeat("\xff", (int) ($bits / 8));
        if ($bits % 8 !== 0) {
            $mask .= chr(0xff << (8 - ($bits % 8)) & 0xff);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    private function scimError(int $status, string $detail, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            [
                'schemas'  => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'status'   => (string) $status,
                'detail'   => $detail,
            ],
            $status,
            $headers,
        );
    }
}
