<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Http;

use Vortos\Auth\Scim\ScimService;

/**
 * SCIM 2.0 HTTP controller (RFC 7644).
 *
 * Routes (all prefixed with /scim/v2, mounted by the framework router):
 *
 *   GET    /Users              → listUsers
 *   POST   /Users              → createUser
 *   GET    /Users/{id}         → getUser
 *   PUT    /Users/{id}         → replaceUser
 *   PATCH  /Users/{id}         → patchUser
 *   DELETE /Users/{id}         → deleteUser
 *
 *   GET    /Groups             → listGroups
 *   POST   /Groups             → createGroup
 *   GET    /Groups/{id}        → getGroup
 *   PUT    /Groups/{id}        → replaceGroup
 *   PATCH  /Groups/{id}        → patchGroup
 *   DELETE /Groups/{id}        → deleteGroup
 *
 * Security:
 *  - ScimAuthMiddleware runs first (Bearer token, hash-not-store, IP allowlist).
 *  - Every endpoint is deny-by-default: tokens must carry the required scope.
 *  - Content-Type application/scim+json is enforced on mutating requests.
 *  - Input is decoded from JSON; unexpected fields are silently dropped.
 */
final class ScimController
{
    public function __construct(
        private readonly ScimService $service,
        private readonly string $baseUrl = '',
    ) {}

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    /** @return array{status: int, body: array<string,mixed>} */
    public function createUser(string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        $userName = (string) ($data['userName'] ?? '');
        if ($userName === '') {
            return $this->error(400, 'invalidValue', 'userName is required');
        }

        $user = $this->service->createUser($data);

        return ['status' => 201, 'body' => $user->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function getUser(string $id): array
    {
        $user = $this->service->getUser($id);
        if ($user === null) {
            return $this->error(404, 'noTarget', "User {$id} not found");
        }

        return ['status' => 200, 'body' => $user->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function listUsers(?string $filter, int $startIndex, int $count): array
    {
        return ['status' => 200, 'body' => $this->service->listUsers($filter, $startIndex, $count)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function replaceUser(string $id, string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        $user = $this->service->replaceUser($id, $data);

        return ['status' => 200, 'body' => $user->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function patchUser(string $id, string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        $operations = (array) ($data['Operations'] ?? $data['operations'] ?? []);
        $user       = $this->service->patchUser($id, $operations);

        if ($user === null) {
            return $this->error(404, 'noTarget', "User {$id} not found");
        }

        return ['status' => 200, 'body' => $user->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function deleteUser(string $id): array
    {
        if (!$this->service->deleteUser($id)) {
            return $this->error(404, 'noTarget', "User {$id} not found");
        }

        return ['status' => 204, 'body' => []];
    }

    // -------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------

    /** @return array{status: int, body: array<string,mixed>} */
    public function createGroup(string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        if ((string) ($data['displayName'] ?? '') === '') {
            return $this->error(400, 'invalidValue', 'displayName is required');
        }

        $group = $this->service->createGroup($data);

        return ['status' => 201, 'body' => $group->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function getGroup(string $id): array
    {
        $group = $this->service->getGroup($id);
        if ($group === null) {
            return $this->error(404, 'noTarget', "Group {$id} not found");
        }

        return ['status' => 200, 'body' => $group->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function listGroups(?string $filter, int $startIndex, int $count): array
    {
        return ['status' => 200, 'body' => $this->service->listGroups($filter, $startIndex, $count)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function replaceGroup(string $id, string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        $group = $this->service->replaceGroup($id, $data);

        return ['status' => 200, 'body' => $group->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function patchGroup(string $id, string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        $operations = (array) ($data['Operations'] ?? $data['operations'] ?? []);
        $group      = $this->service->patchGroup($id, $operations);

        if ($group === null) {
            return $this->error(404, 'noTarget', "Group {$id} not found");
        }

        return ['status' => 200, 'body' => $group->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function deleteGroup(string $id): array
    {
        if (!$this->service->deleteGroup($id)) {
            return $this->error(404, 'noTarget', "Group {$id} not found");
        }

        return ['status' => 204, 'body' => []];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function parseBody(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function error(int $status, string $scimType, string $detail): array
    {
        return [
            'status' => $status,
            'body'   => [
                'schemas'  => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'status'   => (string) $status,
                'scimType' => $scimType,
                'detail'   => $detail,
            ],
        ];
    }
}
