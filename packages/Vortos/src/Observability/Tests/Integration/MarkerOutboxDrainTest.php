<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Marker\DeployMarker;
use Vortos\Observability\Marker\MarkerEmitterInterface;
use Vortos\Observability\Marker\OutboxMarkerEmitter;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Block 16 §6: a backend outage buffers markers; they drain on recovery; retried
 * deploys never double-annotate (idempotency dedupe).
 */
final class MarkerOutboxDrainTest extends TestCase
{
    private string $spoolPath;

    protected function setUp(): void
    {
        $this->spoolPath = sys_get_temp_dir() . '/vortos-marker-outbox-' . bin2hex(random_bytes(6)) . '/m.spool';
    }

    protected function tearDown(): void
    {
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

    private function marker(string $buildId = 'b1'): DeployMarker
    {
        return new DeployMarker(
            'prod', 'deploy', $buildId, 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', 'title', [],
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    public function test_outage_buffers_then_drains_on_recovery(): void
    {
        $emitter = new ToggleMarkerEmitter(up: false);
        $outbox = new OutboxMarkerEmitter($emitter, new BoundedSpool($this->spoolPath, 1024 * 1024));

        $outbox->emit($this->marker('b1'));
        self::assertSame(0, $outbox->drain(10)); // backend down: re-spooled, nothing delivered
        self::assertFalse((new BoundedSpool($this->spoolPath, 1024 * 1024))->isEmpty());

        $emitter->up = true;
        $outbox = new OutboxMarkerEmitter($emitter, new BoundedSpool($this->spoolPath, 1024 * 1024));
        $drained = $outbox->drain(10);

        self::assertSame(1, $drained);
        self::assertCount(1, $emitter->emitted);
        self::assertTrue((new BoundedSpool($this->spoolPath, 1024 * 1024))->isEmpty());
    }

    public function test_idempotency_key_dedupes_a_retried_emit(): void
    {
        $emitter = new ToggleMarkerEmitter(up: true);
        $outbox = new OutboxMarkerEmitter($emitter, new BoundedSpool($this->spoolPath, 1024 * 1024));

        $outbox->emit($this->marker('b1'));
        $outbox->emit($this->marker('b1')); // retry — same idempotency key
        $outbox->emit($this->marker('b2')); // different build — distinct key

        $drained = $outbox->drain(10);

        self::assertSame(2, $drained);
    }

    public function test_emitter_exception_is_swallowed_never_throws_into_deploy(): void
    {
        $throwing = new class implements MarkerEmitterInterface {
            public function name(): string
            {
                return 'throwing';
            }

            public function emit(DeployMarker $marker): void
            {
                throw new \RuntimeException('backend down');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };

        $outbox = new OutboxMarkerEmitter($throwing, new BoundedSpool($this->spoolPath, 1024 * 1024));
        $outbox->emit($this->marker());

        // drain() must not throw even though the inner emitter always throws.
        $drained = $outbox->drain(10);
        self::assertSame(0, $drained);
    }
}

final class ToggleMarkerEmitter implements MarkerEmitterInterface
{
    /** @var list<DeployMarker> */
    public array $emitted = [];

    public function __construct(public bool $up)
    {
    }

    public function name(): string
    {
        return 'toggle';
    }

    public function emit(DeployMarker $marker): void
    {
        if (!$this->up) {
            throw new \RuntimeException('backend unreachable');
        }

        $this->emitted[] = $marker;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([]);
    }
}
