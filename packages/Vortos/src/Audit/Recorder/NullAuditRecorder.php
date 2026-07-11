<?php

declare(strict_types=1);

namespace Vortos\Audit\Recorder;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\Event\AuditEvent;

/**
 * Default recorder when no storage backend is wired (fresh install, or DBAL absent).
 *
 * It does not silently swallow: it logs at warning level so a misconfigured deployment
 * that is dropping audit events is visible rather than invisible. Replaced by the DBAL
 * store (P2) / async recorder (P3) as soon as those are installed.
 */
final class NullAuditRecorder implements AuditRecorderInterface
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger()) {}

    public function record(AuditEvent $event): void
    {
        $this->logger->warning('Audit event dropped: no audit storage backend is wired.', [
            'audit_action' => $event->action,
            'audit_scope'  => $event->scope->value,
            'audit_id'     => $event->id,
        ]);
    }
}
