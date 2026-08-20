<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http;

use Vortos\Http\Request;

/**
 * The `from`/`to` time window of an audit list request.
 *
 * Parsing is deliberately forgiving: an unparseable date yields null rather than a 500, so a
 * malformed query string degrades to "no bound" instead of failing the whole page. The export
 * path keeps its own stricter parsing in {@see AuditExportRequestParser} — an export is enqueued
 * once and its filter must be exact, whereas a list request is re-issued on every keystroke.
 */
final readonly class AuditWindow
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::parse((string) $request->query->get('from', '')),
            self::parse((string) $request->query->get('to', '')),
        );
    }

    private static function parse(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
