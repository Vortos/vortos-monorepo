<?php

declare(strict_types=1);

namespace Vortos\Alerts\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Vortos\Alerts\Severity;
use Vortos\Observability\Sink\MessageScrubber;

/**
 * A typed, scrubbed, fingerprintable operationally-significant signal (§3.1).
 *
 * Constructed already-scrubbed via {@see MessageScrubber} — `summary`/`annotations`
 * can never carry a planted secret/PII token through to a notifier payload. `labels`
 * are the fingerprint inputs (Dedupe\Fingerprint) and must therefore be small, stable
 * identity data — not free text (free text belongs in `annotations`).
 */
final readonly class AlertEvent
{
    /**
     * @param array<string, string> $labels      fingerprint inputs (ruleId|env|tenantId|labels)
     * @param array<string, string> $annotations human context — scrubbed before storage
     * @param list<string>          $links       dashboard/manifest URLs
     */
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $title,
        public string $summary,
        public AlertSource $source,
        public string $env,
        public ?string $tenantId,
        public array $labels,
        public array $annotations,
        public array $links,
        public DateTimeImmutable $occurredAt,
        public ?string $runbookUrl = null,
    ) {
        if ($ruleId === '') {
            throw new InvalidArgumentException('AlertEvent ruleId must not be empty.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('AlertEvent title must not be empty.');
        }
        if ($env === '') {
            throw new InvalidArgumentException('AlertEvent env must not be empty.');
        }
        foreach (array_keys($labels) as $key) {
            if ($key === '') {
                throw new InvalidArgumentException('AlertEvent labels must be a non-empty string-keyed map of strings.');
            }
        }
    }

    /**
     * The only public constructor path for production code: scrubs `summary` and
     * every annotation value through {@see MessageScrubber} before the VO exists, so
     * a secret/PII token planted in upstream context can never reach a driver.
     *
     * @param array<string, string> $labels
     * @param array<string, string> $annotations
     * @param list<string>          $links
     */
    public static function scrubbed(
        string $ruleId,
        Severity $severity,
        string $title,
        string $summary,
        AlertSource $source,
        string $env,
        ?string $tenantId,
        array $labels,
        array $annotations,
        array $links,
        DateTimeImmutable $occurredAt,
        ?string $runbookUrl = null,
        MessageScrubber $scrubber = new MessageScrubber(),
    ): self {
        $scrubbedAnnotations = [];
        foreach ($scrubber->scrubContext($annotations) as $key => $value) {
            $scrubbedAnnotations[$key] = is_string($value) ? $value : (string) $value;
        }

        return new self(
            $ruleId,
            $severity,
            $title,
            $scrubber->scrub($summary),
            $source,
            $env,
            $tenantId,
            $labels,
            $scrubbedAnnotations,
            $links,
            $occurredAt,
            $runbookUrl,
        );
    }
}
