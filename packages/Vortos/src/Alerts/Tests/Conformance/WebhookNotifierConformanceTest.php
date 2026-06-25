<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Conformance;

use Vortos\Alerts\Notifier\Driver\HttpNotifierTransportInterface;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfGuard;
use Vortos\Alerts\Notifier\Driver\Webhook\WebhookNotifier;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Testing\NotifierConformanceTestCase;

final class WebhookNotifierConformanceTest extends NotifierConformanceTestCase
{
    protected function createNotifier(): NotifierInterface
    {
        $transport = new class implements HttpNotifierTransportInterface {
            public function send(string $url, array $payload, array $headers = []): bool
            {
                return true;
            }
        };

        return new WebhookNotifier($transport, new SsrfGuard(), 'ALERTS_TCK_UNSET_WEBHOOK_URL');
    }

    protected function expectedKey(): string
    {
        return 'webhook';
    }
}
