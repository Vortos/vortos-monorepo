<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev;

use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Messaging\Hook\HandlerOutcome;

/**
 * Stateful per-event renderer for consumer tail output.
 *
 * Retry lines (handlerRetry) are printed immediately so the operator sees
 * live progress during backoff delays. Final handler results are buffered
 * under their event header and written with tree chars (├/└) at flush().
 */
final class TailRenderer
{
    private ?string $lastEventId   = null;
    private ?string $pendingHeader = null;
    /** @var array<int, array{handlerId: string, outcome: HandlerOutcome, attempts: int, latencyMs: float, errorMessage: ?string}> */
    private array $pendingLines = [];

    public function __construct(private readonly OutputInterface $output) {}

    public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void
    {
        if ($eventId === $this->lastEventId) {
            return;
        }

        $this->flush();

        $this->lastEventId   = $eventId;
        $this->pendingLines  = [];
        $this->pendingHeader = sprintf(
            '<fg=gray>%s</>  <info>%-20s</>  <fg=gray>agg: %s  corr: %s</>',
            $time,
            $eventShort,
            $aggId,
            $corr,
        );
    }

    /**
     * Printed immediately — not buffered — so the operator sees retries live during backoff.
     * When this fires the header has not been flushed yet; print and clear it here.
     */
    public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void
    {
        if ($this->pendingHeader !== null && $this->pendingLines === []) {
            $this->output->writeln($this->pendingHeader);
            $this->pendingHeader = null;
        }

        $shortId = $this->shortHandlerId($handlerId);

        $this->output->writeln(sprintf(
            '  <fg=gray>↺</> <fg=gray>%-48s</>  <fg=yellow>attempt %-3d</>  <fg=gray>%5.1fms</>  <fg=yellow>%s</>',
            $shortId,
            $attempt,
            $latencyMs,
            mb_substr($error, 0, 80),
        ));
    }

    public function handlerResult(
        string         $eventId,
        string         $handlerId,
        HandlerOutcome $outcome,
        int            $attempts,
        float          $latencyMs,
        ?string        $errorMessage,
    ): void {
        $this->pendingLines[] = [
            'handlerId'    => $handlerId,
            'outcome'      => $outcome,
            'attempts'     => $attempts,
            'latencyMs'    => $latencyMs,
            'errorMessage' => $errorMessage,
        ];
    }

    public function flush(): void
    {
        if ($this->pendingLines === []) {
            return;
        }

        if ($this->pendingHeader !== null) {
            $this->output->writeln($this->pendingHeader);
        }

        $total = count($this->pendingLines);

        foreach ($this->pendingLines as $i => $line) {
            $isLast  = ($i === $total - 1);
            $tree    = $isLast ? '  └' : '  ├';
            $shortId = $this->shortHandlerId($line['handlerId']);

            [$status, $latency, $suffix] = $this->renderOutcome($line);

            $this->output->writeln(sprintf(
                '%s <fg=gray>%-48s</>  %s  %s%s',
                $tree,
                $shortId,
                $status,
                $latency,
                $suffix,
            ));
        }

        $this->output->writeln('');
        $this->pendingHeader = null;
        $this->pendingLines  = [];
    }

    /** @return array{string, string, string} [status, latency, suffix] */
    private function renderOutcome(array $line): array
    {
        $latency = sprintf('<fg=gray>%5.1fms</>', $line['latencyMs']);

        return match ($line['outcome']) {
            HandlerOutcome::Succeeded => [
                '<info>OK</>  ',
                $latency,
                '',
            ],
            HandlerOutcome::SucceededAfterRetries => [
                '<info>OK</>  ',
                $latency,
                sprintf('  <fg=gray>(after %d %s)</>', $line['attempts'] - 1, $line['attempts'] - 1 === 1 ? 'retry' : 'retries'),
            ],
            HandlerOutcome::SkippedIdempotent => [
                '<fg=yellow>SKIP</>',
                '   —  ',
                '  <fg=gray>(idempotent)</>',
            ],
            HandlerOutcome::DiscardedReplayLimit => [
                '<fg=yellow>SKIP</>',
                '   —  ',
                '  <fg=gray>(replay limit)</>',
            ],
            HandlerOutcome::DeadLettered => [
                '<error>DLQ </error>',
                $latency,
                $line['errorMessage'] !== null
                    ? sprintf('  <fg=yellow>%s</>', mb_substr($line['errorMessage'], 0, 80))
                    : '',
            ],
            default => ['<fg=gray>????</>', $latency, ''],
        };
    }

    private function shortHandlerId(string $handlerId): string
    {
        if (str_contains($handlerId, '::')) {
            [$class, $method] = explode('::', $handlerId, 2);
            $classParts       = explode('\\', $class);
            return end($classParts) . '::' . $method;
        }

        return $handlerId;
    }
}
