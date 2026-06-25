<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver\Null;

use Vortos\Alerts\Notifier\Capability\NotifierCapability;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/** Explicit no-op default when no channel is configured. */
#[AsDriver('null')]
final class NullNotifier implements NotifierInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function notify(NotifierMessage $message): NotificationResult
    {
        return NotificationResult::suppressed('null', 'null driver: no-op by design');
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            NotifierCapability::SupportsPaging->value => false,
            NotifierCapability::SupportsAck->value => false,
            NotifierCapability::RichFormatting->value => false,
            NotifierCapability::OffHost->value => false,
        ]);
    }
}
