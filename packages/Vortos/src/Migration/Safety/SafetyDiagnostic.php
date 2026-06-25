<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

final readonly class SafetyDiagnostic
{
    private const MAX_EXCERPT_LENGTH = 200;

    public string $statementExcerpt;

    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public ?string $table,
        string $statementExcerpt,
        public string $message,
        public string $remediation,
        public ?string $optOutAttribute = null,
    ) {
        $this->statementExcerpt = self::sanitizeExcerpt($statementExcerpt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'optOutAttribute' => $this->optOutAttribute,
            'remediation' => $this->remediation,
            'ruleId' => $this->ruleId,
            'severity' => $this->severity->value,
            'statementExcerpt' => $this->statementExcerpt,
            'table' => $this->table,
        ];
    }

    private static function sanitizeExcerpt(string $excerpt): string
    {
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $excerpt) ?? $excerpt;
        $cleaned = preg_replace("/'[^']{20,}'/", "'…'", $cleaned) ?? $cleaned;

        if (mb_strlen($cleaned) > self::MAX_EXCERPT_LENGTH) {
            $cleaned = mb_substr($cleaned, 0, self::MAX_EXCERPT_LENGTH) . '…';
        }

        return $cleaned;
    }
}
