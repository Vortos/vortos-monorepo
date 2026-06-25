<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Audit;

use DateTimeImmutable;
use Vortos\Alerts\Dedupe\Fingerprint;
use Vortos\Alerts\Escalation\Acknowledgement;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Routing\RoutedDelivery;
use Vortos\Observability\Audit\AuditHashChain;

/**
 * Records every notification + acknowledgement to the Block 16 tamper-evident
 * ledger (§3.7, §4.5, improvement #6) — non-repudiation for "who was paged / who
 * silenced, when." Guarded by the audit-ledger presence in the DI extension, the
 * same pattern Observability uses for its optional Deploy integration.
 */
final class AlertAuditRecorder
{
    public function __construct(
        private readonly AlertAuditViewRepositoryInterface $repository,
        private readonly AuditHashChain $chain,
        private readonly string $hmacKey,
    ) {}

    public function recordNotification(AlertEvent $event, RoutedDelivery $delivery, NotificationResult $result, DateTimeImmutable $now): AlertAuditEntry
    {
        $fingerprint = Fingerprint::of($event);

        return $this->repository->appendNext($event->env, function (int $sequence, string $prevHash) use ($event, $delivery, $result, $fingerprint, $now): AlertAuditEntry {
            return $this->chainEntry(
                $event->env,
                'notification',
                $fingerprint,
                'system',
                $now,
                [
                    'rule_id' => $event->ruleId,
                    'severity' => $event->severity->value,
                    'channel_key' => $delivery->channelKey,
                    'notifier_key' => $delivery->notifierKey,
                    'outcome' => $result->outcome->value,
                    'reason' => $result->reason,
                ],
                $sequence,
                $prevHash,
            );
        });
    }

    public function recordAcknowledgement(Acknowledgement $ack, string $env, DateTimeImmutable $now): AlertAuditEntry
    {
        return $this->repository->appendNext($env, function (int $sequence, string $prevHash) use ($ack, $env, $now): AlertAuditEntry {
            return $this->chainEntry(
                $env,
                'acknowledgement',
                $ack->fingerprint,
                $ack->ackedBy,
                $now,
                ['tier' => $ack->tier],
                $sequence,
                $prevHash,
            );
        });
    }

    /** @param array<string, mixed> $data */
    private function chainEntry(
        string $env,
        string $eventType,
        string $fingerprint,
        string $actorId,
        DateTimeImmutable $occurredAt,
        array $data,
        int $sequence,
        string $prevHash,
    ): AlertAuditEntry {
        $occurredAtStr = $occurredAt->format(DateTimeImmutable::ATOM);
        $entryId = hash('sha256', implode('|', [$env, $sequence, $fingerprint, $eventType, $occurredAtStr]));

        $hashable = [
            'entry_id' => $entryId,
            'sequence' => $sequence,
            'env' => $env,
            'event_type' => $eventType,
            'fingerprint' => $fingerprint,
            'actor_id' => $actorId,
            'occurred_at' => $occurredAtStr,
            'data' => $data,
        ];

        $contentHash = $this->chain->contentHash($hashable, $prevHash);
        $signingMessage = $this->chain->signingMessage($entryId, $sequence, $contentHash, $prevHash);
        $signature = $this->chain->sign($signingMessage, $this->hmacKey);

        return new AlertAuditEntry($entryId, $sequence, $env, $eventType, $fingerprint, $actorId, $occurredAtStr, $data, $prevHash, $contentHash, $signature);
    }
}
