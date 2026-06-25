<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Audit;

use Vortos\Observability\Audit\AuditHashChain;

final class LedgerIacAuditSink implements IacAuditSinkInterface
{
    public function __construct(
        private readonly AuditHashChain $chain,
        private readonly string $hmacKey,
    ) {}

    public function record(LifecycleEvent $event): void
    {
        $this->chain->chain(
            entryId: bin2hex(random_bytes(16)),
            sequence: 0,
            eventType: 'iac.' . $event->phase->value,
            actorId: $event->actor,
            actorIdentitySource: 'cli',
            env: $event->environment,
            buildId: '',
            gitSha: '',
            imageDigest: '',
            schemaFingerprintId: '',
            reason: $event->summary,
            occurredAt: $event->occurredAt,
            data: $event->toArray(),
            prevHash: AuditHashChain::GENESIS_HASH,
            hmacKey: $this->hmacKey,
        );
    }
}
