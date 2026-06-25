<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

use Vortos\OpsKit\Driver\DriverInterface;

/**
 * The one swap point every alert delivery flows through (§3.1, §10).
 *
 * Contract (TCK-enforced, same discipline as `ErrorSinkInterface`): {@see notify()}
 * MUST NOT throw into the dispatcher. A transport failure is returned as
 * {@see NotificationResult::failed()} so {@see OutboxNotifier} can retry / fall back —
 * a notifier outage can never block, fail, or back-pressure the emitting subsystem.
 */
interface NotifierInterface extends DriverInterface
{
    /** Stable lower-kebab key; equals the driver's #[AsDriver] key. */
    public function name(): string;

    public function notify(NotifierMessage $message): NotificationResult;
}
