# Vortos Audit — Operator Runbook

The unified, append-only, hash-chained audit spine. One `audit_events` ledger, one
`AuditTrail` API, per-scope/per-tenant cryptographic chains.

## Configuration (`config/audit.php` + env)

| Key | Env | Default | Meaning |
|-----|-----|---------|---------|
| `hmac_key` | `VORTOS_AUDIT_HMAC_KEY` | `''` | HMAC signing key. **Set in production** or chains are unsigned. Keep off-host (secrets). |
| `strict` | — | `true` | Reject audit actions not in the vocabulary. |
| `async` | — | `false` | Enqueue via Kafka instead of writing in-request. Requires a `vortos.audit` consumer. |
| `failure_mode` | — | `block` | `block` = fail the request if enqueue fails; `drop` = log and continue. |
| `redis_dsn` | `VORTOS_AUDIT_REDIS_DSN` | `''` | Cross-process ingestion idempotency guard. |
| `retention_platform_days` | — | `730` | Hot-retention for the platform chain. |
| `retention_tenant_days` | — | `365` | Default hot-retention per tenant (`0` = never purge). |
| `retention_tenant_overrides` | — | `[]` | `tenantId => days`. |
| `archive_key_prefix` | — | `audit-archive` | Cold-storage key prefix. |

## Writing audit events (app code)

```php
$auditTrail->record(
    Scope::Tenant, $orgId, $actor, 'member.role.granted',
    target: new AuditTarget('membership', $mId, $name),
    context: ['role' => 'ROLE_ORG_ADMIN'],
);
```
Actions must be declared in an `AuditActionProviderInterface`. High-sensitivity actions
(impersonation, exports, destructive ops) should be declared `Sensitivity::High`.

## Day-to-day commands

- `vortos:audit:doctor` — health/config check (signing key, archive target, ingestion mode).
- `vortos:audit:retention --dry-run` — report what retention would archive+purge.
- `vortos:audit:retention` — archive aged records to cold storage, then purge. Schedule daily
  (e.g. via vortos-scheduler). **Refuses to run without a durable archive target.**

## Integrity

- Chains are content-hash linked and HMAC-signed. Verify via `AuditAdminService::verifyChain($chainKey)`
  (`platform` or `tenant:{id}`) — streams batch-by-batch and resumes from the archival checkpoint.
- A `BROKEN` result names the first bad sequence and reason (tampered content / gap / bad link /
  bad signature). Investigate immediately: it means the DB was modified out-of-band.

## Retention safety invariant

Order is **write → checkpoint → delete**. A crash can never delete un-archived data. Purge only
removes a contiguous prefix; the checkpoint's tail hash keeps the remaining hot chain verifiable.

## Metrics (Prometheus)

`vortos_audit_events_ingested_total{scope}`, `..._ingest_duplicates_total`,
`..._ingest_failures_total`, `..._verify_failures_total`, `..._archived_total`, `..._purged_total`.
Alert on `verify_failures_total > 0` (tampering) and rising `ingest_failures_total` (broker issues).

## Async ingestion topology (when `async=true`)

Request → `AsyncAuditRecorder` → EventBus → outbox → Kafka → `vortos.audit` consumer →
`AuditIngestionHandler` → per-chain append. Run the consumer worker; without it, events never land.
