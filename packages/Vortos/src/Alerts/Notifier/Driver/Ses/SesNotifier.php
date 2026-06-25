<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver\Ses;

use Throwable;
use Vortos\Alerts\Notifier\Capability\NotifierCapability;
use Vortos\Alerts\Notifier\Driver\EnvLookup;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\AwsSes\ImmediateMailer;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Thin adapter over the existing `vortos-aws-ses` {@see ImmediateMailer} — SES is not
 * re-implemented; this driver only maps a {@see NotifierMessage} onto an {@see Email}
 * and reuses the mailer's own circuit-breaker/outbox/failover (§3.6). Registered only
 * when `vortos-aws-ses` is installed (class-existence guarded in the DI extension).
 */
#[AsDriver('ses')]
final class SesNotifier implements NotifierInterface
{
    public function __construct(
        private readonly ImmediateMailer $mailer,
        private readonly string $fromEnvVar = 'ALERTS_SES_FROM',
        private readonly string $toEnvVar = 'ALERTS_SES_TO',
    ) {}

    public function name(): string
    {
        return 'ses';
    }

    public function notify(NotifierMessage $message): NotificationResult
    {
        $from = EnvLookup::string($this->fromEnvVar);
        $toRaw = EnvLookup::string($this->toEnvVar);
        if ($from === null || $toRaw === null) {
            return NotificationResult::failed('ses', 'SES from/to address not configured');
        }

        $recipients = array_filter(array_map('trim', explode(',', $toRaw)));
        if ($recipients === []) {
            return NotificationResult::failed('ses', 'SES "to" list is empty');
        }

        $lines = [$message->body];
        foreach ($message->fields as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $value);
        }
        if ($message->runbookUrl !== null) {
            $lines[] = "Runbook: {$message->runbookUrl}";
        }

        try {
            $email = Email::new()->from($from)->subject(sprintf('[%s] %s', strtoupper($message->severity->value), $message->title));
            foreach ($recipients as $recipient) {
                $email = $email->to($recipient);
            }
            $email = $email->textBody(implode("\n", $lines));
            $email->validate();

            $this->mailer->send($email);
        } catch (Throwable $e) {
            return NotificationResult::failed('ses', $e->getMessage());
        }

        return NotificationResult::delivered('ses');
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
