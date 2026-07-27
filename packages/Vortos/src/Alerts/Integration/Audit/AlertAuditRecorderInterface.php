<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Audit;

use DateTimeImmutable;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Routing\RoutedDelivery;

/**
 * The seam through which {@see \Vortos\Alerts\AlertDispatcher} records what it paged.
 *
 * Exists so the dispatcher depends on a contract rather than a final concrete class. That is not
 * ceremony: the dispatcher could not be tested against a fake recorder at all, which is part of why
 * "the recorder is registered but nothing calls it" survived — the behaviour was unobservable from
 * a test, so no test asserted it.
 */
interface AlertAuditRecorderInterface
{
    /**
     * Record one delivery attempt and its outcome.
     *
     * Implementations MUST tolerate being called for failed deliveries: "we tried to page and the
     * webhook rejected it" is the entry an incident review most needs.
     */
    public function recordNotification(
        AlertEvent $event,
        RoutedDelivery $delivery,
        NotificationResult $result,
        DateTimeImmutable $now,
    ): AlertAuditEntry;

    /**
     * Whether this recorder can actually write signed entries right now.
     *
     * The dispatcher deliberately swallows recording failures — losing a page is worse than losing
     * its ledger entry — which means a recorder that can never write is invisible from the alert
     * path by design. So the ability to write is asked as a QUESTION here, and the deploy gate asks
     * it, rather than being inferred from the silence.
     */
    public function isOperational(): bool;
}
