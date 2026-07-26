<?php

declare(strict_types=1);

namespace Vortos\Alerts\Runtime;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Integration\AlertSourceInterface;

/**
 * Drives every registered {@see AlertSourceInterface} once per tick.
 *
 * The missing half of the alerting pipeline. Rules, evaluator, dedupe, flap damping, escalation and
 * notifier were all in place, and five sources were wired into the container — but nothing ever
 * called tick(), so a fully configured config/alerts.php produced no alerts at all. This is what
 * closes that loop.
 *
 * One failing source never stops the others: a disk probe that throws must not suppress the DLQ
 * alert evaluated after it. That is the whole point of alerting, so failures are logged and the
 * loop continues.
 */
final class AlertSourceTicker
{
    /**
     * @param iterable<AlertSourceInterface> $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return list<DispatchResult>
     */
    public function tick(string $env, DateTimeImmutable $now): array
    {
        $results = [];

        foreach ($this->sources as $source) {
            try {
                foreach ($source->tick($env, $now) as $result) {
                    $results[] = $result;
                }
            } catch (Throwable $e) {
                $this->logger?->error('Alert source failed during tick; other sources continue.', [
                    'source'    => $source::class,
                    'exception' => $e,
                ]);
            }
        }

        return $results;
    }
}
