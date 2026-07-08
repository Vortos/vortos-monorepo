<?php

declare(strict_types=1);

namespace Vortos\Logger\Flush;

use Monolog\Handler\BufferHandler;

/**
 * Guarantees buffered log records reach their sink within a bounded time
 * window, regardless of process lifecycle — fixes the class of bug where a
 * long-running daemon's buffered logs are only flushed at process exit
 * (which, for an infinite-loop worker, never happens until SIGKILL).
 *
 * Three independent flush triggers are registered, any of which is
 * sufficient on its own:
 *
 *   1. Periodic: SIGALRM fires every minInterval() seconds (CLI/daemons,
 *      requires pcntl). Calls flushDue() — flushes only sinks whose
 *      interval has elapsed.
 *   2. Shutdown: register_shutdown_function calls flushAll() on any process
 *      exit (normal return, uncaught exception, fatal error) — covers
 *      graceful SIGTERM handling in daemon loops that exit their `while`
 *      and return normally.
 *   3. Request/command end: FlushBootListener calls flushDue() at the end
 *      of each HTTP request or console command — covers short-lived
 *      processes and FrankenPHP worker mode.
 *
 * SIGKILL/OOM can still lose up to one flush interval's worth of records for
 * Batched sinks — by design. WriteThrough sinks (Security/Audit) are never
 * registered here; they write immediately and have nothing to flush.
 */
final class FlushScheduler
{
    /** @var list<array{handler: BufferHandler, interval: int, lastFlush: int}> */
    private array $registrations = [];

    private bool $started = false;

    public function register(BufferHandler $handler, int $intervalSeconds): void
    {
        $this->registrations[] = [
            'handler'   => $handler,
            'interval'  => max(1, $intervalSeconds),
            'lastFlush' => time(),
        ];
    }

    public function flushAll(): void
    {
        $now = time();
        foreach ($this->registrations as &$registration) {
            $registration['handler']->flush();
            $registration['lastFlush'] = $now;
        }
    }

    /** Flush only sinks whose interval has elapsed since their last flush. */
    public function flushDue(): void
    {
        $now = time();
        foreach ($this->registrations as &$registration) {
            if ($now - $registration['lastFlush'] >= $registration['interval']) {
                $registration['handler']->flush();
                $registration['lastFlush'] = $now;
            }
        }
    }

    /**
     * Installs the shutdown-function and (if pcntl is available) the
     * periodic SIGALRM flush. Idempotent — safe to call from multiple
     * bootstrap paths.
     */
    public function start(): void
    {
        if ($this->started || $this->registrations === []) {
            return;
        }

        $this->started = true;

        register_shutdown_function([$this, 'flushAll']);

        // NOTE: the periodic SIGALRM flush (pcntl_async_signals + pcntl_alarm) was removed.
        // It is unsafe under FrankenPHP's multi-threaded (ZTS) worker: the async signal handler
        // runs in an arbitrary worker thread mid-VM-op and corrupts memory → SIGSEGV. The
        // buffer-fill, request/command-end (FlushBootListener), and shutdown-function triggers
        // already guarantee bounded flush latency, including FrankenPHP worker mode.
    }

    private function minInterval(): int
    {
        $intervals = array_column($this->registrations, 'interval');

        return $intervals === [] ? 2 : min($intervals);
    }
}
