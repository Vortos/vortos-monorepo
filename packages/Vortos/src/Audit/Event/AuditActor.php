<?php

declare(strict_types=1);

namespace Vortos\Audit\Event;

use Vortos\Audit\Enum\ActorType;

/**
 * The principal that performed an audited action.
 *
 * `onBehalfOf` captures the impersonation chain: when a platform operator acts as a
 * tenant user (support/impersonation), `actor` is the impersonated identity and
 * `onBehalfOf` is the operator who initiated it — walkable to arbitrary depth. This is
 * the "what admins really did" record enterprise compliance requires; it must never be
 * flattened away.
 */
final readonly class AuditActor
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string     $id,
        public ActorType  $type,
        public string     $label,
        public array      $roles = [],
        public ?AuditActor $onBehalfOf = null,
    ) {}

    public static function user(string $id, string $label, array $roles = []): self
    {
        return new self($id, ActorType::User, $label, $roles);
    }

    public static function system(string $label = 'system'): self
    {
        return new self('system', ActorType::System, $label);
    }

    /** True when this action was performed under an impersonation/on-behalf-of session. */
    public function isImpersonated(): bool
    {
        return $this->onBehalfOf !== null;
    }

    public function withOnBehalfOf(AuditActor $operator): self
    {
        return new self($this->id, $this->type, $this->label, $this->roles, $operator);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'type'          => $this->type->value,
            'label'         => $this->label,
            'roles'         => $this->roles,
            'on_behalf_of'  => $this->onBehalfOf?->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:         (string) $data['id'],
            type:       ActorType::from((string) $data['type']),
            label:      (string) $data['label'],
            roles:      array_values(array_map('strval', (array) ($data['roles'] ?? []))),
            onBehalfOf: isset($data['on_behalf_of']) && is_array($data['on_behalf_of'])
                ? self::fromArray($data['on_behalf_of'])
                : null,
        );
    }
}
