<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Conformance;

use Vortos\Alerts\Notifier\Driver\HttpNotifierTransportInterface;
use Vortos\Alerts\Notifier\Driver\Telegram\TelegramNotifier;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Testing\NotifierConformanceTestCase;

final class TelegramNotifierConformanceTest extends NotifierConformanceTestCase
{
    protected function createNotifier(): NotifierInterface
    {
        $transport = new class implements HttpNotifierTransportInterface {
            public function send(string $url, array $payload, array $headers = []): bool
            {
                return true;
            }
        };

        return new TelegramNotifier($transport, 'ALERTS_TCK_UNSET_BOT_TOKEN', 'ALERTS_TCK_UNSET_CHAT_ID');
    }

    protected function expectedKey(): string
    {
        return 'telegram';
    }
}
