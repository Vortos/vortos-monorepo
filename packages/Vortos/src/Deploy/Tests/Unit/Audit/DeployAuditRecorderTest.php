<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Audit\ActorIdentitySource;
use Vortos\Deploy\Audit\DeployAuditRecorder;
use Vortos\Deploy\Audit\DeployAuditSinkInterface;
use Vortos\Deploy\Domain\Event\DeployAttempted;
use Vortos\Deploy\Domain\Event\DeployFailed;
use Vortos\Deploy\Domain\Event\DeployRefused;
use Vortos\Deploy\Domain\Event\DeploySucceeded;
use Vortos\Deploy\Domain\Event\RolledBack;
use Vortos\Domain\Event\EventEnvelope;

final class DeployAuditRecorderTest extends TestCase
{
    public function test_attempted_forwards_envelope_to_every_sink(): void
    {
        $received = [];
        $sink = $this->recordingSink($received);

        $recorder = new DeployAuditRecorder([$sink]);
        $recorder->attempted('prod', 'alice', ActorIdentitySource::Oidc, 'build-1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp-1', null);

        self::assertCount(1, $received);
        self::assertInstanceOf(DeployAttempted::class, $received[0]->payload);
        self::assertSame('prod', $received[0]->payload->env);
    }

    public function test_succeeded_refused_failed_rolled_back_each_emit_their_event_type(): void
    {
        $received = [];
        $sink = $this->recordingSink($received);
        $recorder = new DeployAuditRecorder([$sink]);

        $recorder->succeeded('prod', 'alice', ActorIdentitySource::Oidc, 'b1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null, 'ok');
        $recorder->refused('prod', 'alice', ActorIdentitySource::Oidc, 'b1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null, ['check.arch']);
        $recorder->failed('prod', 'alice', ActorIdentitySource::Oidc, 'b1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null, 'RuntimeException', 'boom');
        $recorder->rolledBack('prod', 'alice', ActorIdentitySource::Oidc, 'b1', 'b0', null);

        self::assertCount(4, $received);
        self::assertInstanceOf(DeploySucceeded::class, $received[0]->payload);
        self::assertInstanceOf(DeployRefused::class, $received[1]->payload);
        self::assertInstanceOf(DeployFailed::class, $received[2]->payload);
        self::assertInstanceOf(RolledBack::class, $received[3]->payload);
    }

    public function test_a_throwing_sink_never_propagates_and_other_sinks_still_run(): void
    {
        $received = [];
        $throwingSink = new class implements DeployAuditSinkInterface {
            public function handle(EventEnvelope $envelope): void
            {
                throw new \RuntimeException('sink is down');
            }
        };
        $okSink = $this->recordingSink($received);

        $recorder = new DeployAuditRecorder([$throwingSink, $okSink]);

        // Must not throw.
        $recorder->attempted('prod', 'alice', ActorIdentitySource::Local, 'b1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null);

        self::assertCount(1, $received);
    }

    public function test_zero_sinks_is_a_safe_no_op(): void
    {
        $recorder = new DeployAuditRecorder([]);

        $recorder->attempted('prod', 'alice', ActorIdentitySource::Local, 'b1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null);

        $this->addToAssertionCount(1);
    }

    /**
     * @param list<EventEnvelope> $received
     */
    private function recordingSink(array &$received): DeployAuditSinkInterface
    {
        return new RecordingDeployAuditSink($received);
    }
}

final class RecordingDeployAuditSink implements DeployAuditSinkInterface
{
    /** @param list<EventEnvelope> $received */
    public function __construct(private array &$received)
    {
    }

    public function handle(EventEnvelope $envelope): void
    {
        $this->received[] = $envelope;
    }
}
