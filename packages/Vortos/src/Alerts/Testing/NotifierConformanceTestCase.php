<?php

declare(strict_types=1);

namespace Vortos\Alerts\Testing;

use Throwable;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Severity;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The notifier TCK (§3.6, §6) every {@see NotifierInterface} driver must pass. The
 * defining contract: {@see NotifierInterface::notify()} MUST NOT throw into the
 * dispatcher — even when the driver is wholly unconfigured (no destination secret in
 * the environment) — and MUST always return a typed {@see NotificationResult}.
 */
abstract class NotifierConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createNotifier(): NotifierInterface;

    protected function createDriver(): NotifierInterface
    {
        return $this->createNotifier();
    }

    final public function test_name_matches_registered_key(): void
    {
        self::assertSame($this->expectedKey(), $this->createNotifier()->name());
    }

    final public function test_notify_never_throws(): void
    {
        $notifier = $this->createNotifier();

        try {
            $notifier->notify($this->sampleMessage());
            $this->addToAssertionCount(1); // reaching here = it did not throw
        } catch (Throwable $e) {
            self::fail(sprintf('notify() must never throw; got %s: %s', $e::class, $e->getMessage()));
        }
    }

    final public function test_notify_returns_typed_result(): void
    {
        $result = $this->createNotifier()->notify($this->sampleMessage());

        self::assertInstanceOf(NotificationResult::class, $result);
    }

    final public function test_notify_repeated_call_never_throws(): void
    {
        $notifier = $this->createNotifier();
        $message = $this->sampleMessage();

        $notifier->notify($message);
        $notifier->notify($message);
        $this->addToAssertionCount(1);
    }

    private function sampleMessage(): NotifierMessage
    {
        return new NotifierMessage(
            idempotencyKey: 'tck-' . static::class,
            severity: Severity::Warning,
            title: 'TCK probe',
            body: 'Conformance test probe message.',
            fields: ['env' => 'test'],
            links: [],
        );
    }
}
