<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Http;

/**
 * SCIM 2.0 discovery endpoints (RFC 7644 §4):
 *  GET /ServiceProviderConfig
 *  GET /ResourceTypes
 *  GET /Schemas
 */
final class ScimDiscoveryController
{
    public function __construct(
        private readonly string $baseUrl = '',
    ) {}

    /** @return array<string, mixed> */
    public function serviceProviderConfig(): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => '',
            'patch'  => ['supported' => true],
            'bulk'   => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => false],
            'sort'   => ['supported' => false],
            'etag'   => ['supported' => false],
            'authenticationSchemes' => [
                [
                    'name'             => 'OAuth Bearer Token',
                    'description'      => 'Authentication scheme using the OAuth Bearer Token Standard',
                    'specUri'          => 'http://www.rfc-editor.org/info/rfc6750',
                    'documentationUri' => '',
                    'type'             => 'oauthbearertoken',
                    'primary'          => true,
                ],
            ],
            'meta' => [
                'resourceType' => 'ServiceProviderConfig',
                'location'     => $this->baseUrl . '/ServiceProviderConfig',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function resourceTypes(): array
    {
        return [
            'schemas'      => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 2,
            'Resources'    => [
                [
                    'schemas'          => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    'id'               => 'User',
                    'name'             => 'User',
                    'endpoint'         => '/Users',
                    'description'      => 'User Account',
                    'schema'           => 'urn:ietf:params:scim:schemas:core:2.0:User',
                    'schemaExtensions' => [
                        [
                            'schema'   => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User',
                            'required' => false,
                        ],
                    ],
                    'meta' => ['location' => $this->baseUrl . '/ResourceTypes/User', 'resourceType' => 'ResourceType'],
                ],
                [
                    'schemas'          => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    'id'               => 'Group',
                    'name'             => 'Group',
                    'endpoint'         => '/Groups',
                    'description'      => 'Group',
                    'schema'           => 'urn:ietf:params:scim:schemas:core:2.0:Group',
                    'schemaExtensions' => [],
                    'meta' => ['location' => $this->baseUrl . '/ResourceTypes/Group', 'resourceType' => 'ResourceType'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function schemas(): array
    {
        return [
            'schemas'      => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 2,
            'Resources'    => [
                $this->userSchema(),
                $this->groupSchema(),
            ],
        ];
    }

    private function userSchema(): array
    {
        return [
            'id'          => 'urn:ietf:params:scim:schemas:core:2.0:User',
            'name'        => 'User',
            'description' => 'User Account',
            'schemas'     => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            'attributes'  => [
                ['name' => 'userName', 'type' => 'string', 'required' => true, 'uniqueness' => 'server'],
                ['name' => 'displayName', 'type' => 'string', 'required' => false],
                ['name' => 'name', 'type' => 'complex', 'required' => false],
                ['name' => 'emails', 'type' => 'complex', 'multiValued' => true, 'required' => false],
                ['name' => 'active', 'type' => 'boolean', 'required' => false],
                ['name' => 'groups', 'type' => 'complex', 'multiValued' => true, 'mutability' => 'readOnly'],
            ],
            'meta' => ['location' => $this->baseUrl . '/Schemas/urn:ietf:params:scim:schemas:core:2.0:User', 'resourceType' => 'Schema'],
        ];
    }

    private function groupSchema(): array
    {
        return [
            'id'          => 'urn:ietf:params:scim:schemas:core:2.0:Group',
            'name'        => 'Group',
            'description' => 'Group',
            'schemas'     => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            'attributes'  => [
                ['name' => 'displayName', 'type' => 'string', 'required' => true],
                ['name' => 'members', 'type' => 'complex', 'multiValued' => true, 'required' => false],
            ],
            'meta' => ['location' => $this->baseUrl . '/Schemas/urn:ietf:params:scim:schemas:core:2.0:Group', 'resourceType' => 'Schema'],
        ];
    }
}
