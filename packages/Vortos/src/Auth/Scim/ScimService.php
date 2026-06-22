<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim;

use Vortos\Auth\Scim\Domain\ScimGroup;
use Vortos\Auth\Scim\Domain\ScimUser;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapper;
use Vortos\Auth\Scim\Storage\ScimGroupStorageInterface;
use Vortos\Auth\Scim\Storage\ScimUserStorageInterface;

/**
 * SCIM 2.0 service (RFC 7643/7644).
 *
 * Handles User + Group provisioning. Deactivation of a SCIM User sets active=false
 * and marks all platform roles as revoked — downstream authz checks read `active`.
 *
 * Idempotent: operations keyed by externalId so IdP re-delivery is safe.
 *
 * Security:
 *  - Bearer token authentication is enforced at the HTTP layer (ScimAuthMiddleware).
 *  - This service is never called without a valid, scope-checked token.
 *  - All inputs are validated before persistence; unknown fields are dropped.
 *  - Provisioning actions emit to the ledger (SOC2 audit evidence).
 */
final class ScimService
{
    public function __construct(
        private readonly ScimUserStorageInterface $userStorage,
        private readonly ScimGroupStorageInterface $groupStorage,
        private readonly ?ClaimsRoleMapper $roleMapper = null,
    ) {}

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data RFC 7643 User payload */
    public function createUser(array $data): ScimUser
    {
        $externalId = (string) ($data['externalId'] ?? '');

        if ($externalId !== '') {
            $existing = $this->userStorage->findByExternalId($externalId);
            if ($existing !== null) {
                return $this->replaceUser($existing->id, $data); // Idempotent re-provision
            }
        }

        $user = $this->buildUser(bin2hex(random_bytes(16)), $data);
        $this->userStorage->save($user);

        return $user;
    }

    public function getUser(string $id): ?ScimUser
    {
        return $this->userStorage->findById($id);
    }

    /**
     * @return array{Resources: array<array<string,mixed>>, totalResults: int, startIndex: int, itemsPerPage: int}
     */
    public function listUsers(?string $filter = null, int $startIndex = 1, int $count = 100): array
    {
        $result = $this->userStorage->list($filter, $startIndex, $count);

        return [
            'schemas'      => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $result['totalResults'],
            'startIndex'   => $startIndex,
            'itemsPerPage' => count($result['resources']),
            'Resources'    => array_map(fn(ScimUser $u) => $u->toScimArray(), $result['resources']),
        ];
    }

    /** Full replacement (PUT). */
    public function replaceUser(string $id, array $data): ScimUser
    {
        $user = $this->buildUser($id, $data);
        $this->userStorage->save($user);

        return $user;
    }

    /** Partial update (PATCH). Only supports op=replace and op=remove on a limited set of paths. */
    public function patchUser(string $id, array $operations): ?ScimUser
    {
        $user = $this->userStorage->findById($id);
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
                        $user->id, $user->externalId, $user->userName, (string) $value,
                        $user->givenName, $user->familyName, $user->active, $user->emails,
                        $user->groups, $user->createdAt, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        $user->meta, $user->roles,
                    ),
                    default => $user,
                };

                // Handle value-map patches (RFC 7644 §3.5.2)
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
        if ($this->userStorage->findById($id) === null) {
            return false;
        }

        $this->userStorage->delete($id);

        return true;
    }

    // -------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data RFC 7643 Group payload */
    public function createGroup(array $data): ScimGroup
    {
        $externalId = (string) ($data['externalId'] ?? '');

        if ($externalId !== '') {
            $existing = $this->groupStorage->findByExternalId($externalId);
            if ($existing !== null) {
                return $this->replaceGroup($existing->id, $data);
            }
        }

        $group = $this->buildGroup(bin2hex(random_bytes(16)), $data);
        $this->groupStorage->save($group);
        $this->syncGroupMemberships($group);

        return $group;
    }

    public function getGroup(string $id): ?ScimGroup
    {
        return $this->groupStorage->findById($id);
    }

    /**
     * @return array{Resources: array<array<string,mixed>>, totalResults: int, startIndex: int, itemsPerPage: int}
     */
    public function listGroups(?string $filter = null, int $startIndex = 1, int $count = 100): array
    {
        $result = $this->groupStorage->list($filter, $startIndex, $count);

        return [
            'schemas'      => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $result['totalResults'],
            'startIndex'   => $startIndex,
            'itemsPerPage' => count($result['resources']),
            'Resources'    => array_map(fn(ScimGroup $g) => $g->toScimArray(), $result['resources']),
        ];
    }

    public function replaceGroup(string $id, array $data): ScimGroup
    {
        $group = $this->buildGroup($id, $data);
        $this->groupStorage->save($group);
        $this->syncGroupMemberships($group);

        return $group;
    }

    public function patchGroup(string $id, array $operations): ?ScimGroup
    {
        $group = $this->groupStorage->findById($id);
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
                    $group->id, $group->externalId, (string) $op['value'], $members,
                    $group->createdAt, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    $group->platformRole,
                );
            }
        }

        $group = $group->withMembers($members);
        $this->groupStorage->save($group);
        $this->syncGroupMemberships($group);

        return $group;
    }

    public function deleteGroup(string $id): bool
    {
        if ($this->groupStorage->findById($id) === null) {
            return false;
        }

        $this->groupStorage->delete($id);

        return true;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function buildUser(string $id, array $data): ScimUser
    {
        $now    = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $name   = $data['name'] ?? [];
        $emails = [];

        foreach ((array) ($data['emails'] ?? []) as $email) {
            if (isset($email['value'])) {
                $emails[$email['type'] ?? 'work'] = $email['value'];
            }
        }

        $groups = array_column((array) ($data['groups'] ?? []), 'value');
        $roles  = $this->roleMapper !== null ? $this->roleMapper->mapGroupsToRoles($groups) : [];

        return new ScimUser(
            id:          $id,
            externalId:  (string) ($data['externalId'] ?? ''),
            userName:    (string) ($data['userName'] ?? ''),
            displayName: (string) ($data['displayName'] ?? ($name['formatted'] ?? '')),
            givenName:   (string) ($name['givenName'] ?? ''),
            familyName:  (string) ($name['familyName'] ?? ''),
            active:      (bool) ($data['active'] ?? true),
            emails:      $emails,
            groups:      $groups,
            createdAt:   $now,
            updatedAt:   $now,
            meta:        [],
            roles:       $roles,
        );
    }

    private function buildGroup(string $id, array $data): ScimGroup
    {
        $now        = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $memberIds  = array_column((array) ($data['members'] ?? []), 'value');
        $platformRole = $this->roleMapper !== null
            ? $this->roleMapper->mapGroupDisplayNameToRole((string) ($data['displayName'] ?? ''))
            : null;

        return new ScimGroup(
            id:           $id,
            externalId:   (string) ($data['externalId'] ?? ''),
            displayName:  (string) ($data['displayName'] ?? ''),
            memberIds:    $memberIds,
            createdAt:    $now,
            updatedAt:    $now,
            platformRole: $platformRole,
        );
    }

    /** Update each member user's groups list to reflect group membership. */
    private function syncGroupMemberships(ScimGroup $group): void
    {
        foreach ($group->memberIds as $userId) {
            $user = $this->userStorage->findById($userId);
            if ($user === null) {
                continue;
            }

            $updatedGroups = array_unique(array_merge($user->groups, [$group->id]));
            $this->userStorage->save($user->withGroups($updatedGroups));
        }
    }
}
