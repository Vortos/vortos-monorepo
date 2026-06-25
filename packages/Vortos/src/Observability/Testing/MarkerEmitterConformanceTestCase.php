<?php

declare(strict_types=1);

namespace Vortos\Observability\Testing;

use Vortos\Observability\Marker\DeployMarker;
use Vortos\Observability\Marker\MarkerEmitterInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The marker-emitter TCK (Block 16, §6). The defining contract:
 * {@see MarkerEmitterInterface::emit()} is best-effort and MUST NOT throw into the
 * caller — even when the underlying transport explodes. Mirrors
 * {@see ErrorSinkConformanceTestCase}.
 */
abstract class MarkerEmitterConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createEmitter(): MarkerEmitterInterface;

    protected function createDriver(): MarkerEmitterInterface
    {
        return $this->createEmitter();
    }

    private function marker(): DeployMarker
    {
        return new DeployMarker(
            'prod', 'deploy', 'build-1', 'abc123', 'sha256:' . str_repeat('a', 64), 'fp-1',
            'Deployed: prod', ['succeeded'], new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    final public function test_name_matches_registered_key(): void
    {
        self::assertSame($this->expectedKey(), $this->createEmitter()->name());
    }

    final public function test_emit_never_throws(): void
    {
        $emitter = $this->createEmitter();
        $emitter->emit($this->marker());
        $this->addToAssertionCount(1);
    }

    final public function test_repeated_emit_with_same_idempotency_key_never_throws(): void
    {
        $emitter = $this->createEmitter();
        $marker = $this->marker();

        $emitter->emit($marker);
        $emitter->emit($marker);
        $this->addToAssertionCount(1);
    }
}
