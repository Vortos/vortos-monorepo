<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver\Webhook;

use Vortos\Alerts\Notifier\Capability\NotifierCapability;
use Vortos\Alerts\Notifier\Driver\EnvLookup;
use Vortos\Alerts\Notifier\Driver\HttpNotifierTransportInterface;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/** Generic JSON-POST webhook — SSRF-hardened via {@see SsrfGuard} (§4.2). */
#[AsDriver('webhook')]
final class WebhookNotifier implements NotifierInterface
{
    public function __construct(
        private readonly HttpNotifierTransportInterface $transport,
        private readonly SsrfGuard $ssrfGuard,
        private readonly string $urlEnvVar = 'ALERTS_WEBHOOK_URL',
    ) {}

    public function name(): string
    {
        return 'webhook';
    }

    public function notify(NotifierMessage $message): NotificationResult
    {
        $url = EnvLookup::string($this->urlEnvVar);
        if ($url === null) {
            return NotificationResult::failed('webhook', 'webhook URL not configured');
        }

        try {
            $this->ssrfGuard->assertSafe($url);
        } catch (SsrfViolationException $e) {
            return NotificationResult::failed('webhook', $e->getMessage());
        }

        $payload = [
            'idempotency_key' => $message->idempotencyKey,
            'severity' => $message->severity->value,
            'title' => $message->title,
            'body' => $message->body,
            'fields' => $message->fields,
            'links' => $message->links,
            'runbook_url' => $message->runbookUrl,
        ];

        if (!$this->transport->send($url, $payload)) {
            return NotificationResult::failed('webhook', 'delivery failed');
        }

        return NotificationResult::delivered('webhook');
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            NotifierCapability::SupportsPaging->value => false,
            NotifierCapability::SupportsAck->value => false,
            NotifierCapability::RichFormatting->value => false,
            NotifierCapability::OffHost->value => true,
        ]);
    }
}
