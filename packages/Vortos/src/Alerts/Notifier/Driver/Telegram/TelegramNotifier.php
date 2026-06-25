<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver\Telegram;

use Vortos\Alerts\Notifier\Capability\NotifierCapability;
use Vortos\Alerts\Notifier\Driver\EnvLookup;
use Vortos\Alerts\Notifier\Driver\HttpNotifierTransportInterface;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Telegram Bot `sendMessage` — the **default** paging channel (free, no approval,
 * push-to-phone; §3.4). The bot token is read from the environment at use-time and
 * is never stored on the instance or logged; the request URL is always
 * `api.telegram.org` (no user-controlled destination), so this driver is exempt
 * from the webhook SSRF guard by construction.
 */
#[AsDriver('telegram')]
final class TelegramNotifier implements NotifierInterface
{
    public function __construct(
        private readonly HttpNotifierTransportInterface $transport,
        private readonly string $botTokenEnvVar = 'ALERTS_TELEGRAM_BOT_TOKEN',
        private readonly string $chatIdEnvVar = 'ALERTS_TELEGRAM_CHAT_ID',
        private readonly string $apiBaseUrl = 'https://api.telegram.org',
    ) {}

    public function name(): string
    {
        return 'telegram';
    }

    public function notify(NotifierMessage $message): NotificationResult
    {
        $botToken = EnvLookup::string($this->botTokenEnvVar);
        $chatId = EnvLookup::string($this->chatIdEnvVar);
        if ($botToken === null || $chatId === null) {
            return NotificationResult::failed('telegram', 'Telegram bot token / chat id not configured');
        }

        $lines = [sprintf('[%s] %s', strtoupper($message->severity->value), $message->title), $message->body];
        foreach ($message->fields as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $value);
        }
        if ($message->runbookUrl !== null) {
            $lines[] = "Runbook: {$message->runbookUrl}";
        }

        $url = sprintf('%s/bot%s/sendMessage', $this->apiBaseUrl, $botToken);
        $payload = ['chat_id' => $chatId, 'text' => implode("\n", $lines)];

        if (!$this->transport->send($url, $payload)) {
            return NotificationResult::failed('telegram', 'delivery failed');
        }

        return NotificationResult::delivered('telegram');
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            NotifierCapability::SupportsPaging->value => true,
            NotifierCapability::SupportsAck->value => false,
            NotifierCapability::RichFormatting->value => false,
            NotifierCapability::OffHost->value => true,
        ]);
    }
}
