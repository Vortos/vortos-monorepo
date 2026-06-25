<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Driver\Glitchtip\GlitchtipErrorSink;
use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorTransportInterface;

/**
 * The §12.4 durability story for errors: while the backend is down, captured errors
 * buffer to the bounded on-disk spool (never blocking, never lost up to the cap); when
 * the backend recovers, they drain in FIFO order. Over the cap, oldest are dropped and
 * counted — the app host is never threatened.
 */
final class ErrorSinkDurabilityTest extends TestCase
{
    private const ENV = 'OBSERVABILITY_GLITCHTIP_DSN';
    private string $spoolPath;

    protected function setUp(): void
    {
        $this->spoolPath = sys_get_temp_dir() . '/vortos-durability-' . bin2hex(random_bytes(6)) . '/e.spool';
        $_ENV[self::ENV] = 'http://localhost/ingest';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV]);
        $dir = dirname($this->spoolPath);
        if (is_dir($dir)) {
            foreach ((array) glob($dir . '/*') as $f) {
                if (is_string($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
    }

    public function test_buffers_during_outage_then_drains_in_order_on_recovery(): void
    {
        $transport = new ToggleTransport(up: false);
        $sink = new GlitchtipErrorSink(new BoundedSpool($this->spoolPath, 1024 * 1024), $transport);

        // Backend down: capture several, flush repeatedly — nothing delivered, all retained.
        foreach (['e1', 'e2', 'e3'] as $m) {
            $sink->capture(CapturedError::fromMessage($m));
        }
        $sink->flush();
        $sink->flush();
        self::assertSame([], $transport->delivered);
        self::assertFalse((new BoundedSpool($this->spoolPath, 1024 * 1024))->isEmpty());

        // Backend recovers: drain delivers everything, FIFO.
        $transport->up = true;
        $sink->flush();

        self::assertSame(['e1', 'e2', 'e3'], $transport->delivered);
        self::assertTrue((new BoundedSpool($this->spoolPath, 1024 * 1024))->isEmpty());
    }

    public function test_over_cap_during_long_outage_drops_oldest_and_counts(): void
    {
        // Tiny cap: only a couple of records fit.
        $spool = new BoundedSpool($this->spoolPath, 16 * 4 + 12);
        $transport = new ToggleTransport(up: false);
        $sink = new GlitchtipErrorSink($spool, $transport);

        for ($i = 0; $i < 20; $i++) {
            $sink->capture(CapturedError::fromMessage('x')); // each ~ small json
        }

        $stats = (new BoundedSpool($this->spoolPath, 16 * 4 + 12))->stats();
        self::assertGreaterThan(0, $stats->droppedTotal, 'Oldest must be dropped under sustained outage.');
        self::assertLessThanOrEqual($stats->maxBytes, $stats->sizeBytes, 'Spool never exceeds its cap.');
    }

    public function test_partial_delivery_resumes_from_failure_point(): void
    {
        $transport = new FailAfterTransport(failAfter: 2);
        $sink = new GlitchtipErrorSink(new BoundedSpool($this->spoolPath, 1024 * 1024), $transport);

        foreach (['a', 'b', 'c', 'd'] as $m) {
            $sink->capture(CapturedError::fromMessage($m));
        }

        $sink->flush(); // delivers a,b then fails on c; c,d re-spooled
        self::assertSame(['a', 'b'], $transport->delivered);

        $transport->failAfter = PHP_INT_MAX; // recovered
        $sink->flush();

        self::assertSame(['a', 'b', 'c', 'd'], $transport->delivered);
    }
}

final class ToggleTransport implements ErrorTransportInterface
{
    /** @var list<string> */
    public array $delivered = [];

    public function __construct(public bool $up) {}

    public function send(string $ingestUrl, CapturedError $error): bool
    {
        if (!$this->up) {
            return false;
        }
        $this->delivered[] = $error->message;

        return true;
    }
}

final class FailAfterTransport implements ErrorTransportInterface
{
    /** @var list<string> */
    public array $delivered = [];

    public function __construct(public int $failAfter) {}

    public function send(string $ingestUrl, CapturedError $error): bool
    {
        if (count($this->delivered) >= $this->failAfter) {
            return false;
        }
        $this->delivered[] = $error->message;

        return true;
    }
}
