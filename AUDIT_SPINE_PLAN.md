# Enterprise Audit Spine — Master Plan

## PLAN V2 — FINAL ARCHITECTURE (decided 2026-07-12, no backward compat)
Target: ONE store; EVERY event (auth+org+platform+payments+…) flows ASYNC via Kafka
(keyed by chain_key) into audit_events, per-(scope,tenant) hash chains, one rich query API,
one console per audience. Locked decisions:
- ASYNC for ALL audit (enable AsyncAuditRecorder + run vortos.audit consumer worker; Kafka
  key = chain_key for per-chain partition ordering).
- AUTH FULLY UNIFIED: PostgresAuditStore forwards into AuditTrail; auth events Scope::Tenant
  when org known else Scope::Platform. Drop vortos.audit_log.
- ADMIN HTTP API in FRAMEWORK (new vortos-audit-admin): provides BOTH platform (.any, scope/
  tenantId params) AND org-own (.own, via TenantContext) read/verify/export endpoints — app
  deletes its hand-written controllers, keeps only vocabulary + record() calls + perms→roles
  mapping + optional bespoke /me route. BOUNDARY RULE: expressible in framework primitives
  (scope, TenantContext tenantId, actorId, targetId, filters, permission) => framework; encodes
  app identity semantics => app (thin, calls AuditAdminService).
- SEARCH = pluggable port AuditSearchIndexInterface; default = Postgres FTS (tsvector/GIN) +
  btree facets in framework; apps can swap OpenSearch/etc. Add action-PREFIX filter + facet counts
  + saved-views store.
- CONFIG = fluent builder VortosAuditConfig (config/audit.php returns a closure taking it), matching
  Scheduler/Messaging convention — NOT the current plain array. Every knob a typed method
  (->async/->consumer/->failureMode/->hmacKeyFromSecret/->strict/->retention/->coldArchive/->search/
  ->rowLevelSecurity/->authEvents/…); secrets by name (stay in sealed env). Fold into F1.
- DB-AGNOSTIC by CONTRACT, Postgres-first DEFAULT: domain+integrity+query+search are pure interfaces
  (swap store impl freely). Make per-chain append lock a STRATEGY — portable DBAL default (SELECT FOR
  UPDATE on a per-chain head row, any transactional DB) + pg_advisory_xact_lock as a PG optimization.
  FTS behind the search port; RLS behind a ->rowLevelSecurity() toggle that no-ops off-PG. Another DB =
  drop-in AuditStore impl, not a rewrite. (Fold portable-lock strategy into F1.)
- MIGRATION all-or-nothing bug: being fixed in another session — treat as SOLVED; ship FTS/GIN indexes
  as normal migrations (no manual out-of-band index creation).
- TENANT ISOLATION: query-layer scoping + Postgres RLS on audit_events (defense-in-depth).
- Retention+cold-archive on scheduler; legacy org_audit_log/platform_audit_log/vortos.audit_log
  Contract-dropped.
## V2 PROGRESS
- F1 DONE (local, green — publishing as alpha-224): fluent `VortosAuditConfig` (closure-loaded
  config/audit.php, HMAC by env-name only) + `AuditExtension::loadConfig` closure loader + legacy-array
  shim; `AuditSearchDriver` enum; Kafka partition key = `chain_key` (AsyncAuditRecorder envelope
  aggregateId=chainKey; KafkaProducer honours aggregate_id as the producev message key);
  `ChainLockStrategyInterface` + `PgAdvisoryChainLock` + portable `RowChainLock` (SELECT..FOR UPDATE on
  `audit_chain_heads`) with DI auto-selecting from the write DSN; new `vortos/vortos-audit-admin` module
  (Vortos\AuditAdmin) shipping the 5 JSON controllers over AuditAdminService + AuditRecordPresenter,
  registered via `vortos.api.controller`; split.yml + root autoload + phpunit suite added; GitHub split
  repo Vortos/vortos-audit-admin created (Packagist reg = user's step). 52 Audit+AuditAdmin tests green.

V2 PHASES: F1 fw(Kafka-key chain_key, async default, vortos-audit-admin module) -> F2 fw(rich
query: prefix+FTS port+facets+saved-views, RLS helpers) -> A1 backend(enable async: declare
vortos.audit consumer+worker, auth->spine, HMAC secret) -> A2 backend(vocabulary: payment.*/
registration.*/auth.* + record() calls) -> A3 backend(adopt fw admin API, delete app controllers,
enable RLS+retention, Contract-drop 3 legacy tables) -> U1 front/U2 admin(faceted filters+search+
saved-views+export+impersonation lens) -> H hardening(security-review, async load test, verify E2E).
Also fix FRAMEWORK_BUGS migrate all-or-nothing before A-phases (blocks index migrations).

---


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
- alpha-211..218 = P1..P7 (framework spine COMPLETE, 37 tests green). alpha-213 was a concurrent
  feature-flags fix (not ours). P3=214, P4=215, P5=216, P6=217, P7=218.
- P8 pt1 (squaura-backend, branch feat/audit-spine-adoption): composer require vortos/vortos-audit
  (locked alpha-217; `composer update` in-container to reach 218), backed-enum AuditAction vocabulary
  (23 cases, replaces OrgAuditAction consts) + AppAuditActionProvider + config/audit.php. Verified
  vs installed pkg. REMAINING P8 pt2: wire AuditTrail into ~34 mutating handlers, run module
  migrations (vortos:migrate:publish+migrate), seed audit perms, then DANGEROUS cutover
  (dual-write->backfill->verify->retire 3 legacy tables+loggers) = needs maintenance window + user go.
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
