<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Messaging;

/**
 * The seam through which vortos-alerts learns about queue backlogs without depending on
 * vortos-messaging.
 *
 * Alerts declares the contract; whichever package owns the tables implements it (vortos-messaging
 * ships DlqBacklogProvider and OutboxBacklogProvider). This is the same dependency direction the
 * deploy/observability boundary uses: the consumer declares the seam, the owner implements it.
 */
interface QueueBacklogProviderInterface
{
    /**
     * A stable identifier for the surface being measured, matched against a rule's `queue` label.
     * Rules without a `queue` label evaluate against every provider.
     */
    public function name(): string;

    /**
     * @return list<QueueBacklog> empty when there is nothing backed up
     */
    public function backlogs(): array;
}
