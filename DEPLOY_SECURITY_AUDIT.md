# Deploy Area Security Audit — Findings & Remediation Backlog

> **Purpose:** Actionable backlog for the vortos CI/CD · Deploy · Secrets · Pipeline audit.
> Written so a fresh session can pick up any item and fix it without re-deriving context.
> **Date:** 2026-07-22 · **Auditor stance:** senior platform/security engineer, enterprise bar.

## Repos involved
- **Framework monorepo:** `~/Documents/vortos/vortos` (packages under `packages/Vortos/src/*`)
- **Backend app (consumes framework, holds generated workflows):** `~/Documents/squaura-backend`
- **Frontend:** `~/Documents/front` (CF Pages auto-deploy; not in scope here)

## Scope audited
`Secrets`, `Pipeline`, `Deploy`, `DeployK8s`, `Docker`, `Iac`, `Release`, `Backup`, `OpsKit`, `Setup`
(~120k LOC) + the actually-generated workflows in `squaura-backend/.github/workflows/` + the
monorepo's own `.github/workflows/split.yml`.

## Overall verdict (context for anyone fixing this)
The **framework-generated** pipeline is genuinely enterprise-grade — do NOT rewrite it. The
crypto (envelope encryption), the deploy-in-image topology (socket-proxy, group-add least
privilege), and the generated `deploy.yml` (SHA-pinned actions, `permissions: contents: read`,
digest-pinned images, provenance+SBOM, StrictHostKeyChecking) are already excellent. **Every
finding below is either (a) a hand-authored workflow that bypasses the framework's own discipline,
or (b) a capability that was built but never wired in.** No live critical vulnerability was found.
Risk rating: **LOW–MODERATE**.

When fixing: match the quality bar the generated `deploy.yml` already sets — that file is the
reference standard for this codebase.

---

## What is already strong (DO NOT regress these while fixing)
- **Envelope crypto:** `packages/Vortos/src/Secrets/Crypto/EnvelopeCipher.php`,
  `Crypto/SecretEnvelope.php` — XChaCha20-Poly1305 AEAD + X25519 `crypto_box_seal` DEK wrap,
  fail-closed.
- **Key custody off-host:** `Secrets/Driver/Age/AgeKeyProvider.php` — private identity from env at
  use-time only, never a tracked file.
- **Zero-plaintext-to-disk:** `Secrets/Driver/File/FileSecretStore.php` — ciphertext-only, atomic
  write, dir 0700 / file 0600. Policed by `Secrets/Tests/Architecture/NoPlaintextToDiskTest`.
- **Action pinning enforced at type level:** `Pipeline/Model/PinnedAction.php` (40-hex SHA regex) +
  `Pipeline/Verification/ActionPinVerifier.php` (upstream existence + runtime-deprecation gates).
- **Subprocess hygiene:** argv arrays, no shell (`Deploy/Execution/ProcessCommandRunner.php`);
  `PGPASSWORD` via env not argv + env allowlist (`Backup/Driver/Postgres/PostgresProcessFactory.php`).
- **Deploy topology:** `Deploy/Builder/RemoteDeployScript.php` — docker-socket-proxy (never raw
  socket), `--group-add` group-only read, `set -euo pipefail`, migrate:analyze DDL gate before
  migrations.
- **IaC guardrails:** `Iac/Terraform/TerraformDocument.php` (SecretLiteralException),
  `Iac/Lifecycle/StateBackend/StateBackendValidator.php` (no local state in prod/staging).
- **Backup immutability probe:** `Backup/Immutability/ImmutabilityVerifier.php` — a successful
  delete of a locked object is treated as a violation.

---

## FINDINGS

> **Progress log (2026-07-22):** F1, F2, F4, F5, G1, G2, G3, G4 **DONE**; F6 verified-safe; F7 fixed.
> F3 = ops-only (no safe code change); G5 = deferred by design (needs per-secret policy first — a
> blanket rotation cron would break deploys). All code findings that can be safely fixed are fixed.

### F1 — ✅ DONE (2026-07-22) — Hand-authored workflows bypass framework pinning + least-privilege
The framework generates perfect workflows, but human-written ones don't follow the same rules.

**Locations & specifics:**
- `~/Documents/squaura-backend/.github/workflows/backup-image.yml`
  - `actions/checkout@v5`, `docker/login-action@v4` → **unpinned mutable tags** (should be SHA).
  - **No `permissions:` block at all** → inherits repo-default token (often read/write). Add
    `permissions: contents: read` at top, grant `packages: write`/`contents: write` only on the
    job that needs it.
  - Builds the sidecar image `FROM` a **mutable tag** (`docker.io/sqoura/sqoura-backend:sha-<gitsha>`),
    not a digest. File comment admits "no digest plumbing across workflows." **Digest-pin the FROM.**
- `~/Documents/vortos/vortos/.github/workflows/split.yml` (framework's OWN CI)
  - `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/setup-node@v4`, and critically the
    **third-party `danharrin/monorepo-split-github-action@v2.4.5`** — all unpinned.
  - **No `permissions:` block.** The split action receives `MONOREPO_SPLIT_TOKEN`, a PAT with push
    rights to ~45 split repos. Unpinned third-party action + broad PAT = classic supply-chain vector.
  - Pin all actions to SHA; add least-privilege `permissions:`; consider narrowing the PAT scope.

**Fix acceptance:** every `uses:` in every workflow across both repos is a 40-hex SHA with a `# vX`
comment; every workflow has an explicit top-level `permissions:` block defaulting to `contents: read`.

**RESOLVED 2026-07-22:**
- `backup-image.yml`: `actions/checkout` + both `docker/login-action` pinned to KnownActionFactory
  SHAs (`93cb6efe…` #v5, `af1e73f9…` #v4.4.0); added top-level `permissions: contents: read`; sidecar
  now built FROM a **digest** resolved at build time (`docker buildx imagetools inspect … .Manifest.Digest`)
  instead of the mutable `:sha-<gitsha>` tag → closes **G4** too.
- `split.yml`: all actions pinned to KnownActionFactory SHAs (checkout `93cb6efe…` #v5, setup-php
  `f3e473d1…` #v2, setup-node `a0853c24…` #v5, monorepo-split `14e42e24…` #v2.4.5); added top-level
  `permissions: contents: read`; added a `pin-lint` job that the privileged `split` job now `needs:`.
- All four SHAs were verified against real upstream tags via `git ls-remote` before committing.
- `ci-alert.yml` was reviewed and left unchanged — it already had `permissions: contents: read`, uses
  no third-party actions, and handles untrusted `workflow_run.*` fields env-only via `jq --arg`
  (closes **F6** as verify-only: no injection surface).

### F2 — ✅ DONE (2026-07-22) — Cosign signing/verification + CVE gate wired into the emitter (also closes G1, G2)
A full supply-chain package exists but was unused by the running pipeline.
- **Exists:** `packages/Vortos/src/Security/SupplyChain/Driver/Cosign/CosignArtifactSigner.php`,
  `Security/SupplyChain/Integration/Pipeline/SupplyChainActions.php`,
  `Security/SupplyChain/Service/SupplyChainManifestDecorator.php`.
- **Missing in `squaura-backend/.github/workflows/deploy.yml`:** no `cosign sign` in the build job,
  no `cosign verify` before the cutover on the VPS. Supply-chain trust currently rests only on
  digest-pinning + `provenance: true`. Digest-pinning bounds the risk (can't tamper a known digest),
  but there is no cryptographic "this digest was produced by our pipeline" gate.

**RESOLVED 2026-07-22 (emitter-driven, the durable fix):**
- Root cause found: `PipelineDefinition::$emitScanGate` and `$emitSign` were plumbed through the
  builder/factory but **never consumed by `PipelineBuilder`** — dead flags. `SupplyChainActions`
  (Security package) could not be referenced from `PipelineBuilder` (Pipeline package) without
  inverting the clean-arch layering, so the pins were added to Pipeline's own verified registry.
- `Pipeline/Builder/KnownActionFactory.php`: added `cosignInstaller()` (sigstore/cosign-installer
  v3.9.1 `398d4b0e…`) and `trivyImageScan()` (aquasecurity/trivy-action v0.36.0 `ed142fd0…`), both
  verified via `git ls-remote`, both added to `all()` → automatically covered by
  `pipeline:actions:verify` and the pin-lint.
- `Pipeline/Builder/PipelineBuilder.php` `buildImageStage()` now, when the flags are on, emits:
  - **G2 CVE gate** — Trivy scan of the pushed **digest**, `exit-code: 1` on fixable HIGH/CRITICAL.
  - **F2 sign + G1 verify** — cosign install → `cosign sign --yes <digest>` (keyless Fulcio, no
    standing key) → `cosign verify` pinning `--certificate-identity-regexp` to this repo/workflow and
    `--certificate-oidc-issuer` to GitHub's OIDC. Because deploy pulls the same digest and `needs:
    build`, a bad signature fails the build before any cutover.
  - Adds `id-token: write` to the build job when `emitSign` (Fulcio needs the OIDC token) — no longer
    only when the deploy posture is OIDC.
- Tests: 5 new cases in `PipelineBuilderBuildStageTest` (scan on/off, sign+verify on, id-token,
  default-off); `KnownActionFactoryTest` count 9→11 + supply-chain-present assertion. Full Pipeline
  suite green (553). PHPStan clean on changed files.
- App enablement: `squaura-backend/config/pipeline.php` → `->emitScanGate(true)->emitSign(true)`.
- `squaura-backend/.github/workflows/deploy.yml` build job updated to the **byte-identical** emitter
  output (verified with `diff` against a real emitter run) so it is LIVE now and reproduced verbatim
  on the next regen. **Caveat:** the backend consumes the framework via Composer; until the framework
  ships through the split/release flow, a premature `pipeline:generate` in the backend with the
  *old* installed emitter would drop these steps. Re-run `pipeline:generate` after the framework
  release + `composer update` to canonicalize.

**Not yet done (documented follow-up):** runtime verify-before-cutover ON the VPS via the existing
`SignatureVerificationCheck` preflight (`Security/SupplyChain/Integration/Deploy/`). That needs cosign
provisioned on the box + a `VerificationPolicy` config and can't be validated here without the host.
The CI-side `cosign verify` already gates every release; the preflight would add depth.

### F7 — ✅ DONE (2026-07-22) — Dormant `SupplyChainActions` SHAs were fabricated / unresolvable
Found while wiring F2: `Security/SupplyChain/Integration/Pipeline/SupplyChainActions.php` pinned
`cosign-installer` and `trivy-action` to SHAs that **do not resolve to any upstream commit** (the
contract test only checked 40-hex *format*, not existence, so it passed). A fabricated SHA is worse
than a mutable tag — it would have failed closed at `pipeline:actions:verify`, or if unverified,
pinned to nothing. **Fixed** to the same verified SHAs as `KnownActionFactory` (v3.9.1 / v0.36.0).
Follow-up worth considering: extend the contract test / pin-verify to assert upstream existence for
`SupplyChainActions::all()` too (not just format), and dedupe the two registries.

### F3 — ⚠️ OPS-ONLY (no code change) — Long-lived standing secrets on the Docker Hub path
`deploy.yml` uses `DOCKER_TOKEN` + `VORTOS_DEPLOY_SSH_KEY` + `VORTOS_AGE_IDENTITY` (all long-lived
GitHub secrets). Framework supports OIDC zero-standing-secret
(`Pipeline/Tests/Architecture/OidcZeroStandingSecretTest.php`) but Docker Hub can't consume it —
accepted registry limitation, not a defect.
**Fix (hardening):** scope the Docker Hub token to a single repo; schedule rotation via the existing
`Secrets/Service/RotationManager.php` (`rotateIfDue`); evaluate migrating primary registry to GHCR to
unlock the OIDC path already built.

### F4 — ✅ DONE (2026-07-22) — `FileSecretStore` temp-file permission race
**RESOLVED:** `Secrets/Driver/File/FileSecretStore.php::save()` now wraps the temp-file write in
`umask(0077)` (restored in a `finally`), so the file is created **0600 from the first byte** instead
of being written at the umask default (often 0644) and chmod-ed after — closing the world/group-read
window (the dir is not guaranteed 0700 if it pre-existed). Also unlinks the temp file if the atomic
rename fails. Proven with a standalone test: under a loose 0022 umask the old path yields 0644, the
new path yields 0600. PHPStan clean. (Secrets unit suite needs the sodium ext, absent in this local
CLI — but the change is on the non-crypto file-write path.)
Original finding text below for reference.
--- original ---
### F4 (orig) — LOW — `FileSecretStore` temp-file permission race
`packages/Vortos/src/Secrets/Driver/File/FileSecretStore.php` (`save()`): writes temp via
`file_put_contents` (default umask, possibly 0644) then `chmod 0600` after. Brief world-readable
window. **Ciphertext only — no plaintext exposure**, and the parent dir is 0700, so minor.
**Fix:** create the temp file with restrictive mode up front (e.g. `fopen`+`chmod` before write, or
set umask), so it is never group/world-readable even momentarily.

### F5 — ✅ DONE (2026-07-22) — `RemoteDeployScript` interpolates config paths into shell unescaped
**RESOLVED at the config boundary (fail-closed), not by escaping at emit time** — `escapeshellarg`
would corrupt the `${{ }}` GitHub expressions and bash var-expansions embedded in the same generated
lines. `PipelineDefinition`'s constructor now rejects whitespace AND shell metacharacters
(`` ` `` `$ ; & | < > ( ) " ' \ * ? ! { } [ ]`) in every field interpolated verbatim into the remote
script / `docker run`: `remoteDeployDir`, `appNetwork`, `runtimeEnvFiles`, `runtimeFileSecretDirs`,
and — previously **unvalidated** — `sealedEnvFile` and `sealedEnvRevealScript`. Shared
`hasShellMetachar()` helper. 9 new tests (data-provider of injection attempts + a legit-path case).
Full Pipeline suite green (562); full-project PHPStan clean (my files contribute 0 errors).
Original finding text below.
--- original ---
### F5 (orig) — LOW (defense-in-depth) — `RemoteDeployScript` interpolates config paths into shell unescaped
`packages/Vortos/src/Deploy/Builder/RemoteDeployScript.php`: `deployDir`, `appNetwork`,
`runtimeEnvFiles`, `sealedEnvRevealScript`, `preCutoverCommands` are concatenated directly into
generated bash. These are **developer config, not runtime attacker input**, so not exploitable
today. A path with a space/metachar would break or, under hostile config, inject.
**Fix:** `escapeshellarg`/validate these values at generation time.

### F6 — ✅ VERIFIED SAFE (2026-07-22) — `ci-alert.yml` untrusted fields
`~/Documents/squaura-backend/.github/workflows/ci-alert.yml`: PR title / branch / actor
(`github.event.workflow_run.*`) are passed as **env vars** (not inline into an eval'd run block), so
injection is already mitigated.
**Fix (verify only):** confirm the Slack step consumes them as env vars and never interpolates them
into a shell command. Keep env-only.

---

## GAPS (absent controls, not broken code)

### G1 — ✅ DONE (2026-07-22) — No image signature verification gate
Resolved as part of F2: `cosign verify` (pinned identity + issuer) runs in the build job on the exact
digest that deploys; `deploy` needs `build`, so verification gates every release. Runtime
verify-before-cutover preflight remains the documented depth follow-up (see F2).

### G2 — ✅ DONE (2026-07-22) — No dependency/container CVE scan gate
Resolved as part of F2: emitter now emits a Trivy scan-and-fail step (fixable HIGH/CRITICAL) on the
pushed digest, enabled via `->emitScanGate(true)`.

### G3 — ✅ DONE (2026-07-22) — No permissions/pinning lint over the whole `.github/` tree
`PipelineActionsVerifyCommand` only checks that KnownActionFactory pins EXIST upstream; it does not
scan workflow files for unpinned `uses:`. **Resolved** with a new committed linter
`tools/ci/lint-action-pins.sh` (in BOTH repos) that fails on any `uses:` not pinned to a 40-hex SHA
(skips local `./` and `docker://` refs; excludes trailing `# vX` comments). Wired as:
- monorepo `split.yml` → `pin-lint` job, which `split` now `needs:`.
- backend → new hand-authored `.github/workflows/pin-lint.yml` (push + PR + dispatch), so it also
  guards the emitter-generated `deploy.yml`.
Tested: passes on both repos today; negative-tested that it flags a mutable `@v4` and ignores SHA
pins + local actions.

### G4 — ✅ DONE (2026-07-22) — Backup sidecar image not digest-pinned
Resolved as part of F1: `backup-image.yml` now resolves the app tag to its content digest and builds
`FROM` the digest.

### G5 — ⚠️ DEFERRED BY DESIGN (2026-07-22) — Secret rotation is on-demand, not scheduled
**Not blind-wired on purpose.** `SecretMetadata` stores NO per-secret rotation policy (only key,
versions, currentVersionId), and `RotationManager::rotateIfDue` takes a policy argument. A scheduled
"rotate all due" would therefore apply ONE blanket policy to EVERY secret in the provider — including
externally-managed ones. Auto-rotating the age KEK (`VORTOS_AGE_IDENTITY`) would invalidate every
sealed envelope and **break deploys**; rotating the Docker token would break registry auth. That is
the opposite of bulletproof.
**Prerequisite design (do first):** add an opt-in rotation policy per secret (e.g. a
`RotationPolicy` persisted in `SecretMetadata`, or an explicit allowlist of rotatable keys). THEN add
a `secrets:rotate-due` command that enumerates `SecretsProviderInterface::list()` and only rotates
keys that carry a policy, and register it on the Vortos Scheduler. Only app-managed secrets in the
provider are candidates — the deploy KEK and Docker token stay operator-rotated (see F3).

### G5 (orig) — Secret rotation is on-demand, not scheduled
`Secrets/Service/RotationManager.php::rotateIfDue` exists but no scheduled invocation wires it to a
cadence. **Fix:** register a Vortos Scheduler job to call rotation on policy interval.

---

## Automation status (reference — no action needed)
Application delivery IS fully automated tag→prod via the vortos packages + GitHub + Docker Hub + VPS:
config (`config/pipeline.php`) → `vortos:pipeline:generate` emits `deploy.yml` → tag push runs
`tests → analyse → agnosticism → build → deploy` → SSH to VPS → run-in-image
(migrate:analyze gate → provision → record-manifest → doctor → app seed/search → blue-green cutover
via socket-proxy). Backup sidecar + Slack CI alerts fire on `workflow_run`.
**Intentionally manual (correct trust boundary — do not automate):** one-time VPS bootstrap
(deploy user, SSH key, first age-store delivery, GitHub secrets/vars), `vortos:iac:apply`, and
rollback decisions.

---

## Suggested fix order (highest ratio first)
1. **F1 + G4** — pin actions, add `permissions:` blocks, digest-pin backup sidecar (Low effort, biggest gap). Repos: both.
2. **G2** — add Grype/Trivy CVE gate to build job + Pipeline emitter.
3. **G3** — lint all workflows with `PipelineActionsVerifyCommand` in CI.
4. **F2 + G1** — wire existing Cosign sign+verify into build and `RemoteDeployScript`.
5. **F3 + G5** — scope/rotate Docker token; schedule rotation; evaluate GHCR/OIDC.
6. **F4 + F5 + F6** — temp-file perms, escapeshellarg config paths, verify ci-alert env-only.

## Key files to touch (quick index)
- Generated workflows (regenerate, don't hand-edit where emitter-managed):
  `~/Documents/squaura-backend/.github/workflows/{deploy.yml,backup-image.yml,ci-alert.yml}`
- Framework CI: `~/Documents/vortos/vortos/.github/workflows/split.yml`
- Pipeline emitter/generator: `packages/Vortos/src/Pipeline/` (esp. `Builder/`, `Console/`,
  `Emitter/`, `Definition/`)
- Remote deploy script: `packages/Vortos/src/Deploy/Builder/RemoteDeployScript.php`
- Supply chain (Cosign): `packages/Vortos/src/Security/SupplyChain/`
- Secrets: `packages/Vortos/src/Secrets/{Driver/File/FileSecretStore.php,Service/RotationManager.php}`
- App pipeline config: `~/Documents/squaura-backend/config/pipeline.php`

---

## CHANGELOG
### 2026-07-22 — F1, G3, G4 fixed; F6 verified safe
Files changed:
- `squaura-backend/.github/workflows/backup-image.yml` — pin checkout + 2× docker/login-action;
  add `permissions: contents: read`; digest-pin sidecar `FROM` (resolve tag→digest at build time).
- `squaura-backend/.github/workflows/pin-lint.yml` — NEW hand-authored pin-lint workflow.
- `squaura-backend/tools/ci/lint-action-pins.sh` — NEW linter (copy of the monorepo one).
- `vortos/vortos/.github/workflows/split.yml` — pin all 4 actions to KnownActionFactory SHAs;
  add `permissions: contents: read`; add `pin-lint` job; `split` now `needs: [pin-lint, tests, ui-build]`.
- `vortos/vortos/tools/ci/lint-action-pins.sh` — NEW linter (source of truth).

Verification performed:
- All pinned SHAs confirmed against upstream tags with `git ls-remote` (setup-node→v5.0.0,
  monorepo-split→v2.4.5, checkout→v5.0.1); setup-php/login SHAs already proven in live deploy.yml.
- All edited/created YAML parses (`yaml.safe_load`).
- Linter self-tested: passes on both repos; correctly fails on a mutable `@v4` and skips `./` locals.

NOT committed to git — changes are on disk in both working trees for review. Nothing pushed.

### Still open (next session)
F2+G1 (wire existing Cosign sign/verify), G2 (Grype/Trivy CVE gate), F3 (scope/rotate Docker token
+ schedule rotation), F4 (FileSecretStore temp perms), F5 (escapeshellarg config paths in
RemoteDeployScript), G5 (schedule secret rotation).

### 2026-07-22 (part 2) — F2, G1, G2 fixed; F7 found & fixed
Framework (monorepo):
- `Pipeline/Builder/KnownActionFactory.php` — add cosignInstaller (v3.9.1) + trivyImageScan (v0.36.0), both in all().
- `Pipeline/Builder/PipelineBuilder.php` — consume emitScanGate/emitSign; emit CVE gate + cosign sign/verify; id-token on emitSign.
- `Pipeline/Tests/Unit/Builder/PipelineBuilderBuildStageTest.php` — +5 tests.
- `Pipeline/Tests/Unit/Builder/KnownActionFactoryTest.php` — count 9→11 + supply-chain assertion.
- `Security/SupplyChain/Integration/Pipeline/SupplyChainActions.php` — fix fabricated cosign/trivy SHAs (F7).
App (squaura-backend):
- `config/pipeline.php` — enable emitScanGate + emitSign.
- `.github/workflows/deploy.yml` — build job updated to byte-identical emitter output (verified via diff vs real emitter run).
Verification: full Pipeline suite green (553); PHPStan clean on changed files; all SHAs `git ls-remote`-verified;
generated-vs-hand-applied build job diff = identical; pin-lint passes both repos. The 20 Security SupplyChain
test errors are pre-existing/environmental (`sodium_crypto_sign_keypair` undefined in the bare CLI run), unrelated.
Nothing committed/pushed — on disk in both working trees.
