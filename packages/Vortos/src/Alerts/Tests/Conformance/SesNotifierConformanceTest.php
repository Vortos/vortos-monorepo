<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Conformance;

use Vortos\Alerts\Notifier\Driver\Ses\SesNotifier;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Testing\NotifierConformanceTestCase;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ImmediateMailer;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class SesNotifierConformanceTest extends NotifierConformanceTestCase
{
    protected function createNotifier(): NotifierInterface
    {
        $inner = new class implements MailerInterface {
            public function send(Email $email): SentEmail
            {
                throw new \RuntimeException('boom — proves notify() never throws even when the mailer explodes');
            }
        };

        return new SesNotifier(new ImmediateMailer($inner), 'ALERTS_TCK_UNSET_SES_FROM', 'ALERTS_TCK_UNSET_SES_TO');
    }

    protected function expectedKey(): string
    {
        return 'ses';
    }
}
