<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Driver\Glitchtip\GlitchtipErrorSink;
use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorTransportInterface;

/** A transport double recording deliveries and switchable between success/failure. */
final class RecordingTransport implements ErrorTransportInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function __construct(public bool $succeeds = true, public bool $throws = false) {}

    public function send(string $ingestUrl, CapturedError $error): bool
    {
        if ($this->throws) {
            throw new RuntimeException('transport exploded');
        }
        if (!$this->succeeds) {
            return false;
        }
        $this->sent[] = $error->message;

        return true;
    }
}

final class GlitchtipErrorSinkTest extends TestCase
{
    private const ENV = 'OBSERVABILITY_GLITCHTIP_DSN';
    private string $spoolPath;

    protected function setUp(): void
    {
        $this->spoolPath = sys_get_temp_dir() . '/vortos-errsink-' . bin2hex(random_bytes(6)) . '/e.spool';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);
        putenv(self::ENV);
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

    private function sink(RecordingTransport $transport): GlitchtipErrorSink
    {
        return new GlitchtipErrorSink(new BoundedSpool($this->spoolPath, 1024 * 1024), $transport);
    }

    public function test_name_is_driver_key(): void
    {
        self::assertSame('glitchtip', $this->sink(new RecordingTransport())->name());
    }

    public function test_declares_off_host_and_disk_buffering(): void
    {
        $caps = $this->sink(new RecordingTransport())->capabilities();

        self::assertTrue($caps->supports(SinkCapability::OffHost));
        self::assertTrue($caps->supports(SinkCapability::DiskBuffering));
    }

    public function test_capture_spools_and_flush_delivers(): void
    {
        $_ENV[self::ENV] = 'http://localhost/ingest';
        $transport = new RecordingTransport(succeeds: true);
        $sink = $this->sink($transport);

        $sink->capture(CapturedError::fromMessage('boom-one'));
        $sink->capture(CapturedError::fromMessage('boom-two'));
        $sink->flush();

        self::assertSame(['boom-one', 'boom-two'], $transport->sent);
    }

    public function test_flush_without_dsn_is_noop_and_keeps_records(): void
    {
        $transport = new RecordingTransport(succeeds: true);
        $sink = $this->sink($transport);

        $sink->capture(CapturedError::fromMessage('held'));
        $sink->flush(); // no DSN set

        self::assertSame([], $transport->sent);
        self::assertFalse((new BoundedSpool($this->spoolPath, 1024 * 1024))->isEmpty());
    }

    public function test_flush_respools_remaining_on_failure(): void
    {
        $_ENV[self::ENV] = 'http://localhost/ingest';
        $transport = new RecordingTransport(succeeds: false);
        $sink = $this->sink($transport);

        $sink->capture(CapturedError::fromMessage('a'));
        $sink->capture(CapturedError::fromMessage('b'));
        $sink->flush(); // fails on first; everything re-spooled

        self::assertFalse((new BoundedSpool($this->spoolPath, 1024 * 1024))->isEmpty());
    }

    public function test_capture_never_throws_even_when_payload_too_large(): void
    {
        // A spool whose cap can't hold the record: capture must still not throw.
        $sink = new GlitchtipErrorSink(new BoundedSpool($this->spoolPath, 32), new RecordingTransport());

        $sink->capture(CapturedError::fromMessage(str_repeat('x', 5000)));
        $this->addToAssertionCount(1);
    }

    public function test_flush_never_throws_when_transport_explodes(): void
    {
        $_ENV[self::ENV] = 'http://localhost/ingest';
        $sink = $this->sink(new RecordingTransport(throws: true));

        $sink->capture(CapturedError::fromMessage('a'));
        $sink->flush();
        $this->addToAssertionCount(1);
    }
}
