<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Serializer;

use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * Serialises a {@see StoredAuditEvent} to the camelCase JSON the audit consoles consume
 * (org trail + platform console). The impersonation chain is emitted recursively as
 * `onBehalfOf`, so a support/on-behalf-of session surfaces its full actor chain.
 */
final class AuditRecordPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(StoredAuditEvent $stored): array
    {
        $e = $stored->event;

        return [
            'id'          => $e->id,
            'scope'       => $e->scope->value,
            'tenantId'    => $e->tenantId,
            'actor'       => self::actor($e->actor),
            'action'      => $e->action,
            'target'      => $e->target === null ? null : [
                'type'  => $e->target->type,
                'id'    => $e->target->id,
                'label' => $e->target->label,
            ],
            'sensitivity' => $e->sensitivity->value,
            'outcome'     => $e->outcome->value,
            'source'      => [
                'ip'        => $e->source->ip,
                'userAgent' => $e->source->userAgent,
                'sessionId' => $e->source->sessionId,
                'requestId' => $e->source->requestId,
                'deviceId'  => $e->source->deviceId,
            ],
            'context'     => (object) $e->context,
            'occurredAt'  => $e->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'sequence'    => $stored->sequence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function actor(AuditActor $actor): array
    {
        return [
            'id'         => $actor->id,
            'type'       => $actor->type->value,
            'label'      => $actor->label,
            'roles'      => $actor->roles,
            'onBehalfOf' => $actor->onBehalfOf === null ? null : self::actor($actor->onBehalfOf),
        ];
    }
}
