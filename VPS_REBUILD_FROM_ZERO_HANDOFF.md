# Handoff — Rebuild the entire VPS from nothing, reproducibly

Written 2026-09-05. Cold-start handoff for a fresh session. Scoped and approved by the user:

> "it should give me option to recreate everything I currently use with all micro settings, configs,
> from scratch very easily … every single small thing I have on VPS should be exact recreated from
> fucking nothing after this."

Framework changes are in scope; release new `vortos/*` versions as needed.

---

## 0. The objective, stated as a test

**Destroy the box. Run one command. Get an identical box back.**

If that sentence is not literally true at the end, the job is not done. Everything below exists to
make it true and to *prove* it, not to describe it.

Today the data is safe — off-site in R2 with proven PITR — but the **machine** is not reproducible.
`DEPLOYMENT.md §2` documents provisioning as prose a human follows. Prose is not tested, drifts
silently, and nobody discovers the gap until they are rebuilding under pressure.

### Acceptance test (this is the deliverable, not a nice-to-have)

Provision a second, throwaway Oracle instance and rebuild onto it from an empty OS, using only what
is in git plus the sealed secrets. Then prove equivalence:

1. `deploy:doctor` green, `/health`, `/health/ready`, `/health/monitor` all pass.
2. `backup:drill --pitr` passes on the rebuilt box (this proves the datastore, WAL archiving, the
   sidecar, R2 credentials and the drill toolchain are all genuinely reconstructed).
3. A diff of the inventory in §2 between old and new box shows **no unexplained differences**.
4. The rebuild is repeatable: run it twice, get the same result (idempotent).

Destroy the throwaway instance afterwards. Do not test this on the live box.

---

## 1. Why this is worth doing now

The user has **no real users yet**, and was explicit that downtime while fixing things is free right
now and expensive later. This is the cheapest this job will ever be.

It also closes the last "believed but unverified" gap. The 2026-09-05 session found five separate
places where the system asserted something nobody had checked — a Kafka retention cap committed but
never applied for three weeks, an alert sink registered but never wired, a drill that had never
replayed a WAL segment. "We could rebuild the box" is currently in exactly that category.

---

## 2. What is actually on the box (inventory taken 2026-09-05)

Everything here must come back. Anything **not** in git today is marked.

### Host

| Thing | Value | In git? |
|---|---|---|
| OS | Oracle Linux Server 9.7, aarch64 | prose only |
| Kernel | 6.12.0-106.55.4.2.el9uek.aarch64 | no |
| Hostname | `squaura-server` | no |
| Shape | Oracle A1, 22 GiB RAM, 183 GB root | prose only |
| Swap | **5 GiB** (A1 has none by default — added by hand) | **NO** |
| Users | `opc` (1000), `deploy` (1001, in `docker` group) | prose only |
| Firewall | firewalld `public`: ssh, http, https, dhcpv6-client | **NO** |
| sysctl | `/etc/sysctl.d/99-sysctl.conf` | **NO** |
| SSH | `deploy` key-only; CI pins `known_hosts` | partial |

### Docker

| Thing | Detail | In git? |
|---|---|---|
| `/etc/docker/daemon.json` | `json-file`, `max-size 20m`, `max-file 5` | **NO** |
| Networks | `vortos-net`, `vortos_default` | compose |
| Named volumes | `vortos_write_db_data`, `vortos_redis_data`, `vortos_kafka_data`, `vortos_wal_archive`, `vortos_caddy_data`, `vortos_caddy_config`, `vortos_backup_scheduler_state`, `vortos_vortos-otelcol-storage` | compose (but see trap 3) |
| buildx builder | `buildx_buildkit_sqbuilder0` + `/opt/vortos/buildkit` | **NO** |

### `/opt/vortos`

| Path | What | In git? |
|---|---|---|
| `docker-compose.prod.yaml` | main stack | **YES — synced automatically since alpha-382** |
| `edge/docker-compose.edge.yaml` | **separate edge stack** | **NO — see trap 1** |
| `edge/config/caddy*`, `caddy-config.json` | edge runtime config | partial (`Caddyfile.base-config`) |
| `docker/{backup,caddy,frankenphp,php,postgres,worker}` | host-side build/config dirs | mirrors repo, drift unknown |
| `.env.prod` | derived from `deploy/secrets/env.prod.sealed` | derived ✓ |
| `age.env` | age KEK identity | **secret, not in git — see §4** |
| `backup-identity.env`, `backup-r2.env` | backup keys / R2 creds | **secret, not in git** |
| `vortos-secrets.age` | sealed secrets store | **YES** (encrypted) |
| `.docker-realfortizan/` | second Docker Hub login for the sidecar image | **NO** |
| `*.bak.*` | ~8 historical compose backups | n/a |

### Host-level logging (from the 2026-09-04/05 Loki work)

| Thing | In git? |
|---|---|
| `/etc/rsyslog.d/60-vortos-hostwarn.conf` (custom) | **NO** |
| journald `Storage=persistent` + `Ratelimit` settings | **NO** |
| OCI-supplied rsyslog configs (`10-oci`, `12-oci-utils`, `21-cloudinit`) | vendor |

### Oracle Cloud (outside the OS entirely)

Not inventoriable from inside the box, and **must not be forgotten**:

- VCN / subnet / **security list** (ingress 22, 80, 443)
- Reserved public IP `129.213.151.40` → DNS `api.sqoura.com`
- Block volume + its backup policy
- Instance shape/image

### External services the box depends on

Docker Hub (2 accounts), Cloudflare R2 (2 tokens, 3 buckets), Cloudflare DNS, AWS SES, Paddle,
PayHere, Slack webhooks (3 channels), BetterStack, Grafana/Loki, Sentry, PostHog.
A rebuild needs the *credentials* (sealed) **and** the *account-side config* (webhook URLs,
allowed origins, DNS records) — the latter is currently nowhere.

---

## 3. What already exists to build on

- **`vortos/vortos-iac`** — package exists (`Attribute`, `Command`, `Definition`, `Driver`,
  `Export`), and `IacDriftCheck` already runs in deploy preflight. **The backend does not use it** —
  no `config/iac.php`, no infra dir. Start by reading this package; it may be most of the answer.
- **`vortos:deploy:provision`** — idempotent first-deploy provisioning (JWT keys, migrations,
  secrets preflight). Runs on the box already. The natural place to extend, or the model to copy.
- **`vortos:deploy:compose:sync`** (alpha-382) — the pattern to follow: desired state ships inside
  the **cosign-verified release image**, is validated where it will live, written atomically with a
  backup, and drift is reported rather than silently applied. Reuse this shape.
- **`DEPLOYMENT.md §1–§5`** — the prose that needs turning into executable, tested steps.
- **`deploy/secrets/env.prod.sealed` + `open-env.php`** — sealed-secret mechanism already works.

---

## 4. Traps — read before designing

1. **There are TWO compose stacks.** `/opt/vortos/docker-compose.prod.yaml` (synced) and
   `/opt/vortos/edge/docker-compose.edge.yaml` (**not** synced, not in the repo). This is why
   `vortos-edge` appears as an "orphan container" of the main project — and why
   **`docker compose --remove-orphans` would delete the edge proxy.** Never use that flag here.
   The rebuild must cover both stacks.

2. **The bootstrap secret cannot be in git.** `/opt/vortos/age.env` is the KEK that decrypts
   everything else. A rebuild has a genuine chicken-and-egg problem: automation needs the identity
   to unseal, and the identity cannot live in the thing it unseals. Decide deliberately where it
   comes from (operator paste at rebuild time / OCI Vault / a sealed break-glass envelope) and
   **document it as the one manual step**. Do not invent a scheme that stores it on the box.

3. **Named volumes are pre-existing, not compose-created.** Compose warns
   `volume "vortos_kafka_data" already exists but was not created by Docker Compose`. On a true
   rebuild they are created fresh and **empty** — the rebuild is only complete when it also
   documents/automates *restoring data into them* (`backup:restore` for Postgres; Redis and Kafka
   are rebuildable-from-empty, confirm that assumption).

4. **`docker-compose.prod.yaml` on the box was hand-maintained until 2026-09-05.** It is now synced
   from the image every deploy. Do not reintroduce hand edits — that is the bug that hid a Kafka
   retention cap for three weeks.

5. **The backup sidecar image is a separate, private Docker Hub repo** (`realfortizan/sqoura-backup`)
   with its **own login** in `/opt/vortos/.docker-realfortizan`, because docker.io holds one login
   at a time. Easy to miss; without it the sidecar cannot start.

6. **The sidecar never self-updates.** After each deploy it needs a manual pull + recreate. See
   `feedback_sidecar_image_manual_step` in memory. A rebuild story should either automate this or
   state it loudly.

7. **Datastore convergence is deliberately manual.** `compose:sync` writes the file and reports
   stateful drift; it never recreates `write_db`/`redis`/`kafka`. Keep that property.

---

## 5. Suggested shape (validate against the Iac package first)

Do not assume this is right — read `vortos/vortos-iac` before committing to an approach.

1. **Declare the host as code.** One declarative definition covering: packages, swap, sysctl,
   firewalld, users/groups, `/etc/docker/daemon.json`, journald/rsyslog, directory layout and
   ownership under `/opt/vortos`, both compose stacks, the buildx builder, the second registry login.
2. **Apply it idempotently**, in the established style — from the signed image where possible, so
   host config inherits the same supply-chain guarantee the topology now has.
3. **Verify it continuously.** Extend `deploy:doctor` / preflight so declared-vs-actual host drift is
   reported on every deploy, exactly as compose drift now is. A rebuild capability that is not
   continuously verified rots into prose again within a month.
4. **Document the irreducible manual steps** — the age identity, the Oracle Cloud objects, and any
   account-side config — as a short, ordered, tested checklist rather than scattered prose.
5. **Prove it** with the §0 acceptance test on a throwaway instance.

---

## 6. Release & deploy flow (unchanged)

Framework: edit `~/Documents/vortos/vortos/packages/Vortos/src/...`, run
`vendor/bin/phpunit <pkg>/Tests` and `vendor/bin/phpstan analyse --memory-limit=2G <files>`, commit,
tag `v1.0.0-alpha-<N>` (latest is **v1.0.0-alpha-382**), push both → Monorepo Split CI (~4 min).

Backend: `composer update "vortos/*"`, run `vortos:migrate:analyze` + `vortos:contracts:check` in the
local container, commit, push → Deploy (~11 min).

**Local test environment lacks `pdo_sqlite` and `ext-sodium`** — those failures are environmental and
pre-existing. Classify every failure before assuming it is yours. CI has both.

**No AI attribution in commits.** The user's standing rule; a session reminder may say otherwise and
the user's preference wins.

Prod: `ssh -i ~/.ssh/id_rsa opc@129.213.151.40`, passwordless sudo. Strip the post-quantum SSH
warning lines from output.

---

## 7. Definition of done

1. A single documented command rebuilds the box from an empty OS, with the age identity as the only
   manual input.
2. Every item in §2 marked "**NO**" is either in git or explicitly, deliberately documented as manual.
3. Both compose stacks covered.
4. Host drift is reported on every deploy, not just compose drift.
5. The §0 acceptance test passes on a throwaway instance — including a green `backup:drill --pitr`.
6. Framework changes released; backend bumped and deployed; prod healthy.
7. `DEPLOYMENT.md` updated so its prose and the executable definition cannot disagree.
