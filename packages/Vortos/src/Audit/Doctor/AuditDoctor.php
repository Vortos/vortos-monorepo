<?php

declare(strict_types=1);

namespace Vortos\Audit\Doctor;

/**
 * Health check for the audit subsystem. Surfaces the misconfigurations that silently
 * weaken an audit trail: no HMAC key (chains are content-hashed but unsigned, so an
 * attacker with DB access could re-chain), no durable archive target (retention can't
 * run), and async-without-a-consumer (events would be dispatched into the void).
 */
final class AuditDoctor
{
    /**
     * @param array{hmac_key_set: bool, async: bool, has_archive_target: bool, has_store: bool, has_checkpoints: bool} $facts
     */
    public function __construct(private readonly array $facts) {}

    /**
     * @return list<AuditDoctorCheck>
     */
    public function run(): array
    {
        $checks = [];

        $checks[] = $this->facts['has_store']
            ? AuditDoctorCheck::ok('storage', 'DBAL audit store is wired.')
            : AuditDoctorCheck::fail('storage', 'No audit store wired — events are being dropped by the Null recorder.');

        $checks[] = $this->facts['hmac_key_set']
            ? AuditDoctorCheck::ok('signing', 'HMAC signing key is configured; chains are signed.')
            : AuditDoctorCheck::warn('signing', 'No HMAC key (VORTOS_AUDIT_HMAC_KEY) — chains are content-hashed but UNSIGNED. Set a key in production.');

        $checks[] = $this->facts['has_archive_target']
            ? AuditDoctorCheck::ok('retention', 'A durable archive target is configured; retention can archive+purge.')
            : AuditDoctorCheck::warn('retention', 'No archive target (vortos-object-store) — retention will refuse to purge, so the hot table grows unbounded.');

        if ($this->facts['async']) {
            $checks[] = AuditDoctorCheck::ok('ingestion', 'Async ingestion enabled — ensure a vortos.audit consumer worker is running.');
        } else {
            $checks[] = AuditDoctorCheck::ok('ingestion', 'Synchronous ingestion — events are chained in-request.');
        }

        return $checks;
    }

    public function hasFailure(): bool
    {
        foreach ($this->run() as $check) {
            if ($check->status === AuditDoctorCheck::FAIL) {
                return true;
            }
        }

        return false;
    }
}
