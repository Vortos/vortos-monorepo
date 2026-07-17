<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http;

use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Export\AuditExportFilter;
use Vortos\Http\Request;

/**
 * Parses an export request's filter from either the JSON body (POST enqueue) or the query
 * string. Shared by the org and platform export controllers so both accept an identical filter
 * vocabulary — the same fields the audit list endpoints expose.
 */
final class AuditExportRequestParser
{
    public static function filter(Request $request): AuditExportFilter
    {
        $body = json_decode((string) $request->getContent(), true);
        $body = \is_array($body) ? $body : [];

        $get = static function (string $key) use ($body, $request): ?string {
            $v = $body[$key] ?? $request->query->get($key);
            $v = \is_string($v) ? trim($v) : $v;
            return $v === null || $v === '' ? null : (string) $v;
        };

        return new AuditExportFilter(
            actorId:        $get('actorId'),
            action:         $get('action'),
            actionPrefix:   $get('actionPrefix'),
            minSensitivity: ($s = $get('minSensitivity')) !== null ? Sensitivity::tryFrom($s) : null,
            outcome:        ($o = $get('outcome')) !== null ? Outcome::tryFrom($o) : null,
            targetType:     $get('targetType'),
            targetId:       $get('targetId'),
            from:           ($f = $get('from')) !== null ? new \DateTimeImmutable($f) : null,
            to:             ($t = $get('to')) !== null ? new \DateTimeImmutable($t) : null,
            search:         $get('search'),
        );
    }
}
