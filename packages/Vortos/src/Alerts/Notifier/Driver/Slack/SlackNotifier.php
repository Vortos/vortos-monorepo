<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver\Slack;

use Vortos\Alerts\Notifier\Capability\NotifierCapability;
use Vortos\Alerts\Notifier\Driver\EnvLookup;
use Vortos\Alerts\Notifier\Driver\HttpNotifierTransportInterface;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfGuard;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfViolationException;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/** Slack incoming-webhook driver — info/warning's default chat channel. */
#[AsDriver('slack')]
final class SlackNotifier implements NotifierInterface
{
    public function __construct(
        private readonly HttpNotifierTransportInterface $transport,
        private readonly SsrfGuard $ssrfGuard,
        private readonly string $webhookUrlEnvVar = 'ALERTS_SLACK_WEBHOOK_URL',
    ) {}

    public function name(): string
    {
        return 'slack';
    }

    public function notify(NotifierMessage $message): NotificationResult
    {
        $url = EnvLookup::string($this->webhookUrlEnvVar);
        if ($url === null) {
            return NotificationResult::failed('slack', 'Slack webhook URL not configured');
        }

        try {
            $this->ssrfGuard->assertSafe($url);
        } catch (SsrfViolationException $e) {
            return NotificationResult::failed('slack', $e->getMessage());
        }

        $lines = [sprintf('*[%s] %s*', strtoupper($message->severity->value), $message->title), $message->body];
        foreach ($message->fields as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $value);
        }
        if ($message->runbookUrl !== null) {
            $lines[] = "Runbook: {$message->runbookUrl}";
        }

        $payload = ['text' => implode("\n", $lines)];

        if (!$this->transport->send($url, $payload)) {
            return NotificationResult::failed('slack', 'delivery failed');
        }

        return NotificationResult::delivered('slack');
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            NotifierCapability::SupportsPaging->value => false,
            NotifierCapability::SupportsAck->value => false,
            NotifierCapability::RichFormatting->value => true,
            NotifierCapability::OffHost->value => true,
        ]);
    }
}
