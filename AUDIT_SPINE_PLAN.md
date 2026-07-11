# Enterprise Audit Spine — Master Plan

Goal: replace the three parallel hand-rolled audit systems (auth `vortos.audit_log`,
app `platform_audit_log`, app `org_audit_log`) with ONE framework-provided audit spine
(`vortos/vortos-audit` + `vortos/vortos-audit-admin`) so every future Vortos app gets
enterprise-grade audit for free, and squaura only ever wires it once.

## Locked decisions
- Package: `vortos/vortos-audit`, namespace `Vortos\Audit`, partitioned table root `audit_events`.
- Admin: `vortos/vortos-audit-admin` (mirrors SchedulerAdmin/FeatureFlagsAdmin).
- Unify strategy: FULL UNIFY — collapse all 3 tables via dual-write -> backfill -> verify -> cutover (Phase 8).
- Cold storage: config-driven archival seam; default = dedicated `sqoura-audit-archive` bucket
  (same $ as reusing backups bucket — S3/OCI bills by bytes, not buckets — but own WORM/object-lock + IAM grant).
- Async ingestion via Kafka + Redis idempotency (standing engineering rule).
- Controlled action vocabulary via BACKED ENUMS (standing rule; fixes OrgAuditAction string consts).

## Publish handshake (every [VORTOS] phase)
commit to main -> `git tag v1.0.0-alpha-N` (next = alpha-211) -> push branch+tag ->
split.yml -> packagist ~15s -> `composer update vortos/*` in squaura-backend.
Module migrations: `vortos:migrate:publish` then `vortos:migrate`.
Log framework bugs to FRAMEWORK_BUGS.md. No AI attribution in commits.

## Phases
- P0  Decisions & seam (done — this file).
- P1  [VORTOS] Domain core: AuditEvent, Scope/Sensitivity/Outcome enums, AuditActionInterface+registry, AuditRecorderInterface.
- P2  [VORTOS] Storage+integrity: partitioned schema, DBAL store, per-scope/per-tenant hash chain, immutability triggers, keyset reader, ChainVerifier.
- P3  [VORTOS] Async ingestion: outbox->Kafka producer, `vortos:audit:worker` consumer, Redis idempotency, failure modes.
- P4  [VORTOS] Retention & tiering: Scheduler sweeper, ObjectStore NDJSON archive, DROP PARTITION, per-tenant policy, real `vortos:audit:retention`.
- P5  [VORTOS] Query + export: keyset query service, signed per-tenant export + integrity manifest.
- P6  [VORTOS] audit-admin module: permission catalog (read/export/verify/admin.any) + HTTP mgmt endpoints.
- P7  [VORTOS] Observability + `vortos:audit:doctor` + RUNBOOK.
- P8  [BACKEND] Adopt+unify: app AuditActionCatalog (backed enums), wire recorder into mutating handlers, migrate 3 tables, retire hand-rolled loggers.
- P9  [BACKEND] Context enrichment: impersonation chain, ip/device/session/request-id middleware, sensitivity tagging, 2FA step-up on export.
- P10 [FRONT] squaura app: org audit trail UI (keyset scroll, filters, sensitivity toggle, export), member activity on new API.
- P11 [ADMIN] sqoura-admin: platform audit console (cross-tenant, verify, retention, exports, impersonation/admin-actions view).
- P12 Hardening: security-review, chain-verify E2E, volume test, prod retention dry-run, docs+memory.

## Progress log
- P1 DONE (local, unpublished): module `packages/Vortos/src/Audit/` — enums (Scope/Sensitivity/
  Outcome/ActorType), value objects (AuditEvent/AuditActor w/ impersonation chain/AuditTarget/
  AuditSource), controlled-vocab (RegisteredAction + AuditActionProviderInterface + registry +
  compiler pass), AuditRecorderInterface (+Null/Buffering), AuditTrail facade (strict vocab +
  sensitivity-floor). Registered in root composer autoload, phpunit `Audit` suite, split.yml matrix.
  12/12 unit tests green. Bug fixed: occurred_at serialized microsecond (Y-m-d\TH:i:s.uP), not ms.

## PUBLISHED
- alpha-212 (P2) storage+integrity: AuditHashChain (content-hash + HMAC signature, canonical
  JSON), StoredAuditEvent, AuditChainVerifier + ChainVerificationResult, AuditReaderInterface,
  DbalAuditStore (per-chain advisory lock, append-only, is a synchronous AuditRecorderInterface),
  migration create_audit_events (uq chain_key+sequence tamper anchor + 5 query indexes), DI storage
  wiring + config/audit.php loader (strict + hmac_key, env VORTOS_AUDIT_HMAC_KEY fallback). 21/21
  green. DESIGN NOTE: tamper-evidence is cryptographic (hash chain + off-host HMAC), matching
  auth/scheduler ledgers — NOT DB triggers/partitioning (framework migration seam is portable
  Schema-diff only). High volume handled via retention+cold-archive (P4); PG declarative
  partitioning is an optional app-side scale add-on, documented, not in the portable core.
- alpha-211 (P1) split to https://github.com/Vortos/vortos-audit — main + tag present. Packagist
  submission = user's step.
- NEW-PACKAGE PUBLISH GOTCHA: on the very first release of a brand-new split package, the tag-
  triggered split job RACES the branch(main)-triggered one and fails with
  "src refspec main does not match any" if it runs before `main` exists on the empty target repo.
  Fix: re-run the failed tag split job after the main split lands (self-resolved here). Prevention:
  push branch main first, wait for its split job to finish, THEN push the tag.

## FIRST-PUBLISH PREREQUISITES (before tag alpha-211)
- New split target repo `vortos-audit` must exist on the org (split.yml pushes to it) — NEW package.
- Packagist must know about `vortos/vortos-audit` (submit once) or `composer require` in backend fails.
- Recommendation: build P2 (storage) locally BEFORE first publish, so v1 is actually adoptable.

## Current-state gaps being closed
duplication(3 systems) | OrgAuditAction string consts | synchronous write path |
no partitioning | no real retention/tiering | offset pagination | no signed export |
no sensitivity | no impersonation chain.
