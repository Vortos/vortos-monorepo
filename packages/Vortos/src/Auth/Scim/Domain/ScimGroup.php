<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Domain;

/**
 * SCIM 2.0 Group resource (RFC 7643 §4.2).
 * Groups map to authorization roles/scopes in the platform RBAC engine.
 */
final class ScimGroup
{
    public const SCHEMAS = ['urn:ietf:params:scim:schemas:core:2.0:Group'];

    /**
     * @param string[] $memberIds SCIM User IDs belonging to this group
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $externalId,
        public readonly string $displayName,
        public readonly array $memberIds,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        /** Mapped platform role slug (e.g. 'flags.admin', 'flags.viewer'). */
        public readonly ?string $platformRole = null,
    ) {}

    public function withMembers(array $memberIds): self
    {
        return new self(
            id: $this->id, tenantId: $this->tenantId, externalId: $this->externalId, displayName: $this->displayName,
            memberIds: $memberIds,
            createdAt: $this->createdAt, updatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            platformRole: $this->platformRole,
        );
    }

    /** @return array<string, mixed> RFC 7643 wire representation */
    public function toScimArray(string $baseUrl = ''): array
    {
        $location = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/Groups/' . $this->id : '';

        return [
            'schemas'     => self::SCHEMAS,
            'id'          => $this->id,
            'externalId'  => $this->externalId,
            'displayName' => $this->displayName,
            'members'     => array_map(
                static fn(string $uid) => [
                    'value'   => $uid,
                    '$ref'    => $baseUrl !== '' ? rtrim($baseUrl, '/') . '/Users/' . $uid : '',
                    'display' => $uid,
                ],
                $this->memberIds,
            ),
            'meta' => [
                'resourceType' => 'Group',
                'created'      => $this->createdAt,
                'lastModified' => $this->updatedAt,
                'location'     => $location,
            ],
        ];
    }
}
