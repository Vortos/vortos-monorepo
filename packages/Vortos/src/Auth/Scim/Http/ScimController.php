<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Http;

use Vortos\Auth\Scim\Exception\ScimRoleForbiddenException;
use Vortos\Auth\Scim\ScimService;
use Vortos\Auth\Scim\Token\ScimTokenRecord;
use Vortos\Http\Request;

/**
 * SCIM 2.0 HTTP controller (RFC 7644).
 *
 * Security: ScimAuthMiddleware (Bearer token, SHA-256 hash lookup, IP allowlist,
 * scope enforcement, Content-Type check) protects all non-discovery endpoints.
 * Tenant context is established from the SCIM token — no JWT needed.
 */
final class ScimController
{
    public function __construct(
        private readonly ScimService $service,
        private readonly string $baseUrl = '',
    ) {}

    private function tokenFrom(Request $request): ?ScimTokenRecord
    {
        $record = $request->attributes->get('_scim_token_record');

        return $record instanceof ScimTokenRecord ? $record : null;
    }

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
        if ($user === null) {
            return $this->error(404, 'noTarget', "User {$id} not found");
        }

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
    public function createGroup(Request $request, string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        if ((string) ($data['displayName'] ?? '') === '') {
            return $this->error(400, 'invalidValue', 'displayName is required');
        }

        try {
            $group = $this->service->createGroup($data, $this->tokenFrom($request));
        } catch (ScimRoleForbiddenException $e) {
            return $this->error(403, 'mutability', $e->getMessage());
        }

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
    public function replaceGroup(Request $request, string $id, string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        try {
            $group = $this->service->replaceGroup($id, $data, $this->tokenFrom($request));
        } catch (ScimRoleForbiddenException $e) {
            return $this->error(403, 'mutability', $e->getMessage());
        }

        if ($group === null) {
            return $this->error(404, 'noTarget', "Group {$id} not found");
        }

        return ['status' => 200, 'body' => $group->toScimArray($this->baseUrl)];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    public function patchGroup(Request $request, string $id, string $body): array
    {
        $data = $this->parseBody($body);
        if ($data === null) {
            return $this->error(400, 'invalidSyntax', 'Request body must be valid JSON');
        }

        $operations = (array) ($data['Operations'] ?? $data['operations'] ?? []);

        try {
            $group = $this->service->patchGroup($id, $operations, $this->tokenFrom($request));
        } catch (ScimRoleForbiddenException $e) {
            return $this->error(403, 'mutability', $e->getMessage());
        }

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
