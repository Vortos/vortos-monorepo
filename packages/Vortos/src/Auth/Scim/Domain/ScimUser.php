<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Domain;

/**
 * SCIM 2.0 User resource (RFC 7643 §4.1 + enterprise extension).
 * Immutable — mutate via withActive(), withGroups(), etc.
 */
final class ScimUser
{
    public const SCHEMAS = [
        'urn:ietf:params:scim:schemas:core:2.0:User',
        'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User',
    ];

    /**
     * @param string[]                $groups   group IDs this user belongs to
     * @param array<string, string>   $emails   type → value (e.g. 'work' → 'user@example.com')
     * @param array<string, mixed>    $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $externalId,
        public readonly string $userName,
        public readonly string $displayName,
        public readonly string $givenName,
        public readonly string $familyName,
        public readonly bool $active,
        public readonly array $emails,
        public readonly array $groups,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly array $meta = [],
        /** Platform role mapping from groups/claims (via ClaimsRoleMapper). */
        public readonly array $roles = [],
    ) {}

    public function withActive(bool $active): self
    {
        return new self(
            id: $this->id, tenantId: $this->tenantId, externalId: $this->externalId, userName: $this->userName,
            displayName: $this->displayName, givenName: $this->givenName, familyName: $this->familyName,
            active: $active, emails: $this->emails, groups: $this->groups,
            createdAt: $this->createdAt, updatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            meta: $this->meta, roles: $this->roles,
        );
    }

    public function withGroups(array $groups): self
    {
        return new self(
            id: $this->id, tenantId: $this->tenantId, externalId: $this->externalId, userName: $this->userName,
            displayName: $this->displayName, givenName: $this->givenName, familyName: $this->familyName,
            active: $this->active, emails: $this->emails, groups: $groups,
            createdAt: $this->createdAt, updatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            meta: $this->meta, roles: $this->roles,
        );
    }

    /** @return array<string, mixed> RFC 7643 wire representation */
    public function toScimArray(string $baseUrl = ''): array
    {
        $location = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/Users/' . $this->id : '';

        return [
            'schemas'    => self::SCHEMAS,
            'id'         => $this->id,
            'externalId' => $this->externalId,
            'userName'   => $this->userName,
            'name'       => [
                'formatted'  => $this->displayName,
                'givenName'  => $this->givenName,
                'familyName' => $this->familyName,
            ],
            'displayName' => $this->displayName,
            'active'      => $this->active,
            'emails'      => array_map(
                static fn(string $type, string $value) => ['type' => $type, 'value' => $value, 'primary' => $type === 'work'],
                array_keys($this->emails),
                array_values($this->emails),
            ),
            'groups' => array_map(
                static fn(string $gid) => ['value' => $gid, '$ref' => ($baseUrl !== '' ? rtrim($baseUrl, '/') . '/Groups/' . $gid : ''), 'display' => $gid],
                $this->groups,
            ),
            'meta' => array_merge([
                'resourceType' => 'User',
                'created'      => $this->createdAt,
                'lastModified' => $this->updatedAt,
                'location'     => $location,
            ], $this->meta),
        ];
    }
}
