<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim;

use Vortos\Auth\Scim\Domain\ScimGroup;
use Vortos\Auth\Scim\Domain\ScimUser;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapper;
use Vortos\Auth\Scim\Storage\ScimGroupStorageInterface;
use Vortos\Auth\Scim\Storage\ScimUserStorageInterface;
use Vortos\Auth\Scim\Token\ScimTokenRecord;
use Vortos\Tenant\TenantContext;

/**
 * SCIM 2.0 service (RFC 7643/7644).
 *
 * Handles User + Group provisioning. Deactivation of a SCIM User sets active=false
 * and marks all platform roles as revoked — downstream authz checks read `active`.
 *
 * Idempotent: operations keyed by externalId so IdP re-delivery is safe.
 *
 * All operations are tenant-scoped via TenantContext (fail-closed). A missing
 * tenant throws MissingTenantContextException before any storage access.
 */
final class ScimService
{
    public function __construct(
        private readonly ScimUserStorageInterface $userStorage,
        private readonly ScimGroupStorageInterface $groupStorage,
        private readonly TenantContext $tenantContext,
        private readonly ?ClaimsRoleMapper $roleMapper = null,
        private readonly ?ScimRoleGuard $roleGuard = null,
        private readonly ?ScimAuditLogger $auditLogger = null,
    ) {}

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data RFC 7643 User payload */
    public function createUser(array $data): ScimUser
    {
        $tenantId   = $this->tenantContext->requireTenantId();
        $externalId = (string) ($data['externalId'] ?? '');

        if ($externalId !== '') {
            $existing = $this->userStorage->findByExternalId($tenantId, $externalId);
            if ($existing !== null) {
                return $this->replaceUser($existing->id, $data)
                    ?? throw new \LogicException('Tenant-scoped user vanished between findByExternalId and replaceUser');
            }
        }

        $user = $this->buildUser(bin2hex(random_bytes(16)), $tenantId, $data);
        $this->userStorage->save($user);

        return $user;
    }

    public function getUser(string $id): ?ScimUser
    {
        return $this->userStorage->findById($this->tenantContext->requireTenantId(), $id);
    }

    /**
     * @return array{Resources: array<array<string,mixed>>, totalResults: int, startIndex: int, itemsPerPage: int}
     */
    public function listUsers(?string $filter = null, int $startIndex = 1, int $count = 100): array
    {
        $result = $this->userStorage->list($this->tenantContext->requireTenantId(), $filter, $startIndex, $count);

        return [
            'schemas'      => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $result['totalResults'],
            'startIndex'   => $startIndex,
            'itemsPerPage' => count($result['resources']),
            'Resources'    => array_map(fn(ScimUser $u) => $u->toScimArray(), $result['resources']),
        ];
    }

    /** Full replacement (PUT). Returns null if the user does not exist in the current tenant. */
    public function replaceUser(string $id, array $data): ?ScimUser
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $existing = $this->userStorage->findById($tenantId, $id);

        if ($existing === null) {
            return null;
        }

        $user = $this->buildUser($id, $tenantId, $data, $existing);
        $this->userStorage->save($user);

        return $user;
    }

    /** Partial update (PATCH). Only supports op=replace and op=remove on a limited set of paths. */
    public function patchUser(string $id, array $operations): ?ScimUser
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $user     = $this->userStorage->findById($tenantId, $id);
        if ($user === null) {
            return null;
        }

        foreach ($operations as $op) {
            $opName = strtolower((string) ($op['op'] ?? ''));
            $path   = strtolower((string) ($op['path'] ?? ''));
            $value  = $op['value'] ?? null;

            if ($opName === 'replace') {
                $user = match ($path) {
                    'active'      => $user->withActive((bool) $value),
                    'displayname' => new ScimUser(
                        $user->id, $user->tenantId, $user->externalId, $user->userName, (string) $value,
                        $user->givenName, $user->familyName, $user->active, $user->emails,
                        $user->groups, $user->createdAt, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        $user->meta, $user->roles,
                    ),
                    default => $user,
                };

                if ($path === '' && is_array($value)) {
                    if (isset($value['active'])) {
                        $user = $user->withActive((bool) $value['active']);
                    }
                }
            }

            if ($opName === 'remove' && $path === 'members') {
                // Removing from groups: handled at the group level
            }
        }

        $this->userStorage->save($user);

        return $user;
    }

    public function deleteUser(string $id): bool
    {
        $tenantId = $this->tenantContext->requireTenantId();
        if ($this->userStorage->findById($tenantId, $id) === null) {
            return false;
        }

        $this->userStorage->delete($tenantId, $id);

        return true;
    }

    // -------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data RFC 7643 Group payload
     * @param ScimTokenRecord|null $token When provided, the role guard verifies the token can provision the mapped role
     */
    public function createGroup(array $data, ?ScimTokenRecord $token = null): ScimGroup
    {
        $tenantId   = $this->tenantContext->requireTenantId();
        $externalId = (string) ($data['externalId'] ?? '');

        if ($externalId !== '') {
            $existing = $this->groupStorage->findByExternalId($tenantId, $externalId);
            if ($existing !== null) {
                return $this->replaceGroup($existing->id, $data, $token)
                    ?? throw new \LogicException('Tenant-scoped group vanished between findByExternalId and replaceGroup');
            }
        }

        $group = $this->buildGroup(bin2hex(random_bytes(16)), $tenantId, $data, $token);
        $this->groupStorage->save($group);
        $this->syncGroupMemberships($group, $token);

        return $group;
    }

    public function getGroup(string $id): ?ScimGroup
    {
        return $this->groupStorage->findById($this->tenantContext->requireTenantId(), $id);
    }

    /**
     * @return array{Resources: array<array<string,mixed>>, totalResults: int, startIndex: int, itemsPerPage: int}
     */
    public function listGroups(?string $filter = null, int $startIndex = 1, int $count = 100): array
    {
        $result = $this->groupStorage->list($this->tenantContext->requireTenantId(), $filter, $startIndex, $count);

        return [
            'schemas'      => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $result['totalResults'],
            'startIndex'   => $startIndex,
            'itemsPerPage' => count($result['resources']),
            'Resources'    => array_map(fn(ScimGroup $g) => $g->toScimArray(), $result['resources']),
        ];
    }

    /** Full replacement (PUT). Returns null if the group does not exist in the current tenant. */
    public function replaceGroup(string $id, array $data, ?ScimTokenRecord $token = null): ?ScimGroup
    {
        $tenantId = $this->tenantContext->requireTenantId();

        if ($this->groupStorage->findById($tenantId, $id) === null) {
            return null;
        }

        $group = $this->buildGroup($id, $tenantId, $data, $token);
        $this->groupStorage->save($group);
        $this->syncGroupMemberships($group, $token);

        return $group;
    }

    public function patchGroup(string $id, array $operations, ?ScimTokenRecord $token = null): ?ScimGroup
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $group    = $this->groupStorage->findById($tenantId, $id);
        if ($group === null) {
            return null;
        }

        $members = $group->memberIds;

        foreach ($operations as $op) {
            $opName = strtolower((string) ($op['op'] ?? ''));
            $path   = strtolower((string) ($op['path'] ?? ''));
            $value  = $op['value'] ?? [];

            if ($opName === 'add' && $path === 'members') {
                $newIds  = array_column((array) $value, 'value');
                $members = array_unique(array_merge($members, $newIds));
            }

            if ($opName === 'remove' && $path === 'members') {
                $removeIds = array_column((array) $value, 'value');
                $members   = array_values(array_diff($members, $removeIds));
            }

            if ($opName === 'replace' && $path === 'members') {
                $members = array_column((array) $value, 'value');
            }

            if ($opName === 'replace' && $path === 'displayname' && isset($op['value'])) {
                $group = new ScimGroup(
                    $group->id, $group->tenantId, $group->externalId, (string) $op['value'], $members,
                    $group->createdAt, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    $group->platformRole,
                );
            }
        }

        $group = $group->withMembers($members);
        $this->groupStorage->save($group);
        $this->syncGroupMemberships($group, $token);

        return $group;
    }

    public function deleteGroup(string $id): bool
    {
        $tenantId = $this->tenantContext->requireTenantId();
        if ($this->groupStorage->findById($tenantId, $id) === null) {
            return false;
        }

        $this->groupStorage->delete($tenantId, $id);

        return true;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * RFC 7643: `groups` is readOnly on User — never derived from the inbound body.
     * On create: groups/roles start empty (populated via Group membership only).
     * On replace: groups/roles carry forward from the existing record.
     */
    private function buildUser(string $id, string $tenantId, array $data, ?ScimUser $existing = null): ScimUser
    {
        $now    = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $name   = $data['name'] ?? [];
        $emails = [];

        foreach ((array) ($data['emails'] ?? []) as $email) {
            if (isset($email['value'])) {
                $emails[$email['type'] ?? 'work'] = $email['value'];
            }
        }

        return new ScimUser(
            id:          $id,
            tenantId:    $tenantId,
            externalId:  (string) ($data['externalId'] ?? ''),
            userName:    (string) ($data['userName'] ?? ''),
            displayName: (string) ($data['displayName'] ?? ($name['formatted'] ?? '')),
            givenName:   (string) ($name['givenName'] ?? ''),
            familyName:  (string) ($name['familyName'] ?? ''),
            active:      (bool) ($data['active'] ?? true),
            emails:      $emails,
            groups:      $existing?->groups ?? [],
            createdAt:   $existing?->createdAt ?? $now,
            updatedAt:   $now,
            meta:        [],
            roles:       $existing?->roles ?? [],
        );
    }

    private function buildGroup(string $id, string $tenantId, array $data, ?ScimTokenRecord $token = null): ScimGroup
    {
        $now        = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $memberIds  = array_column((array) ($data['members'] ?? []), 'value');
        $platformRole = $this->roleMapper !== null
            ? $this->roleMapper->mapGroupDisplayNameToRole((string) ($data['displayName'] ?? ''))
            : null;

        if ($platformRole !== null && $this->roleGuard !== null && $token !== null) {
            $this->roleGuard->assertPermittedRoles($token, [$platformRole]);
        }

        return new ScimGroup(
            id:           $id,
            tenantId:     $tenantId,
            externalId:   (string) ($data['externalId'] ?? ''),
            displayName:  (string) ($data['displayName'] ?? ''),
            memberIds:    $memberIds,
            createdAt:    $now,
            updatedAt:    $now,
            platformRole: $platformRole,
        );
    }

    /** Update each member user's groups list to reflect group membership. */
    private function syncGroupMemberships(ScimGroup $group, ?ScimTokenRecord $token = null): void
    {
        $tenantId = $group->tenantId;

        foreach ($group->memberIds as $userId) {
            $user = $this->userStorage->findById($tenantId, $userId);
            if ($user === null) {
                continue;
            }

            $hadGroup = in_array($group->id, $user->groups, true);
            $updatedGroups = array_unique(array_merge($user->groups, [$group->id]));
            $this->userStorage->save($user->withGroups($updatedGroups));

            if (!$hadGroup && $group->platformRole !== null && $this->auditLogger !== null) {
                $this->auditLogger->logRoleAssignment(
                    tenantId: $tenantId,
                    scimTokenId: $token?->id ?? 'system',
                    userId: $userId,
                    role: $group->platformRole,
                    sourceGroupId: $group->id,
                );
            }
        }
    }
}
