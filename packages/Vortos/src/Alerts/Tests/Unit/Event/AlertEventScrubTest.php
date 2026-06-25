<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Event;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;

final class AlertEventScrubTest extends TestCase
{
    public function test_planted_secret_in_summary_is_redacted(): void
    {
        $event = AlertEvent::scrubbed(
            ruleId: 'r1',
            severity: Severity::Critical,
            title: 'title',
            summary: 'token=fake_stripe_api_key_for_test leaked here',
            source: AlertSource::Deploy,
            env: 'prod',
            tenantId: null,
            labels: [],
            annotations: [],
            links: [],
            occurredAt: new DateTimeImmutable(),
        );

        self::assertStringNotContainsString('fake_stripe_api_key_for_test', $event->summary);
        self::assertStringContainsString('[redacted]', $event->summary);
    }

    public function test_planted_secret_in_annotations_is_redacted(): void
    {
        $event = AlertEvent::scrubbed(
            ruleId: 'r1',
            severity: Severity::Warning,
            title: 'title',
            summary: 'fine',
            source: AlertSource::Health,
            env: 'prod',
            tenantId: null,
            labels: [],
            annotations: ['password' => 'super-secret-value', 'note' => 'contact admin@example.com'],
            links: [],
            occurredAt: new DateTimeImmutable(),
        );

        self::assertSame('[redacted]', $event->annotations['password']);
        self::assertStringNotContainsString('admin@example.com', $event->annotations['note']);
    }

    public function test_empty_rule_id_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AlertEvent(
            ruleId: '',
            severity: Severity::Info,
            title: 't',
            summary: 's',
            source: AlertSource::Health,
            env: 'prod',
            tenantId: null,
            labels: [],
            annotations: [],
            links: [],
            occurredAt: new DateTimeImmutable(),
        );
    }
}
