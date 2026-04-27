<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit\Attribute;

/**
 * Records an audit log entry when this controller or method is called.
 *
 * Accepts string or BackedEnum for action:
 *   #[AuditLog('document.viewed')]
 *   #[AuditLog(AuditAction::DocumentViewed)]
 *   #[AuditLog(AuditAction::DocumentDeleted, include: ['id', 'reason'])]
 *
 * The 'include' parameter specifies route attribute names to capture as metadata.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AuditLog
{
    public readonly string $action;

    public function __construct(
        string|\BackedEnum $action,
        public readonly array $include = [],
    ) {
        $this->action = $action instanceof \BackedEnum ? $action->value : $action;
    }
}
