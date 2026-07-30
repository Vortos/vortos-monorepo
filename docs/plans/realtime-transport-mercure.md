# Realtime transport: move SSE off the PHP worker pool

Status: in progress
Owner: platform
Created: 2026-07-30

## Problem

The app tier has a hard ceiling of **4 concurrent PHP requests**, and each open
browser tab holds one of them roughly 95% of the time.

Verified against production on 2026-07-30:

| Fact | Value | Source |
| --- | --- | --- |
| Prod vCPUs | 4 | `nproc` |
| FrankenPHP worker threads | `WORKER_NUM=4` | `docker/frankenphp/Caddyfile`, confirmed in the running container |
| `FRANKENPHP_MAX_THREADS` | unset — no thread autoscaling | container env |
| FrankenPHP version | v2.11.4 | `frankenphp version` |
| SSE stream lifetime / tick | 30s, blocking `sleep(3)` | `Vortos/Sse/Http/SseStream.php` |
| Client reconnect gap | 1.5s | `front/src/lib/notificationsApi.ts` |
| Mercure module | **already compiled into the prod binary** | `frankenphp list-modules` → `http.handlers.mercure` |
| `ulimit -n` (app + edge containers) | **1024** | `docker exec … ulimit -n` |
| Host RAM | 22 GB total, ~12 GB available | `free -g` |

`SseStream::watch()` occupies a worker thread for its full 30-second lifetime,
sleeping 3 seconds at a time. The client reconnects 1.5s after each close, so a
single idle tab consumes ~95% of one of the four available slots.

Consequence: the fifth concurrent tab does not degrade gracefully. Its requests
queue behind a `sleep(3)` in a thread that will not be released for up to 30
seconds. p99 latency goes to ~30s at five users.

This was derived from code and configuration, not from an observed saturation
event — no load test was run. `notification_sse_streams_opened_total` is
declared but is not exposed on a scraped path, so this condition is currently
invisible in Grafana.

### Why the fix belongs in the framework

The defect is in the framework's realtime *contract*, not in Sqoura's
notification feature. `vortos-sse` currently offers only one way to deliver a
nudge — hold a PHP request open and poll Redis inside it. Any app that adopts
the package inherits the ceiling. Fixing it in the app would leave the trap
armed for the next consumer.

## Secondary findings

### S1 — Caddyfile stub drift is a latent production incident

`Vortos/Docker/stubs/frankenphp/docker/frankenphp/Caddyfile` (the published
template) and `squaura-backend/docker/frankenphp/Caddyfile` (the live file) have
diverged. The live file carries two hand-edits the stub lacks:

- a `servers { trusted_proxies static 172.18.0.0/16; client_ip_headers X-Forwarded-For }` block
- `{http.request.client_ip}` as the rate-limit key, where the stub still uses `{http.request.remote.host}`

`DockerFilePublisher::publish()` defaults to `$overwrite = true`. A
`vortos:docker:publish` would therefore silently revert both edits. Because every
request arrives from the edge container, reverting them collapses every
rate-limit zone into a single platform-wide bucket — "10 auth requests per
minute" would become a platform-wide ceiling that includes token refresh.

Fix: upstream both edits into the stub so the template and the live file agree.

### S2 — File descriptor ceiling at ~500–900 concurrent connections

`ulimit -n` is 1024 in both the app and edge containers. Every live connection
costs one descriptor in each. This becomes the binding constraint immediately
after the SSE fix and is not currently raised anywhere.

Correct place: `RuntimeServiceSpec` (declaration) and `ComposeFile::toArray()`
(rendering) in `vortos-deploy` for the app/worker colors; the `edge` service in
the `docker-compose.prod.yaml` stub for the edge.

### S3 — `/api/auth/*` rate limit is shared across a NAT

10 req/min per IP. Refresh-ahead is ~4/hr/tab so this is fine per user, but a
customer office behind one NAT IP shares the bucket across all its staff,
including logins. Noted, not fixed here — needs a decision on keying.

## Non-goals

This plan removes realtime as a bottleneck. It does **not** make the app tier
scale to thousands of users; see Phase 6. Anyone reading this as "we can now
serve 1000 concurrent users" is misreading it.

## Constraints and accepted trade-offs

**T1 — `local` transport is single-instance.** Mercure's `local` transport keeps
published messages in memory, so a publish reaches only subscribers connected to
that hub instance. Correct today because exactly one app color serves traffic and
the hub runs in the same container as the publishing PHP process. **If two app
containers ever serve simultaneously, a notification written by container A will
never reach a user connected to container B.** This constraint collides directly
with the horizontal-replica option in Phase 6 — those two decisions must be made
together. Escape hatch: switch to a shared transport (Redis or Bolt).

**T2 — Cutover goes briefly quiet.** During a blue/green cutover a subscriber is
still attached to the draining color's hub, which nothing publishes to any more.
Acceptable because the payload is a "refetch now" nudge, not the data: the client
refetches on reconnect and is immediately correct. Mitigation: the edge actively
closes old-color SSE connections at cutover so the browser reconnects rather than
holding a dead pipe.

**T3 — No replay, so refetch-on-connect is load-bearing.** `local` keeps no
history, so a nudge published while a client is reconnecting is genuinely lost —
there is no `Last-Event-ID` replay. The client **must** refetch the feed on every
connect to recover the true unread count. The current client already does this;
it must be preserved deliberately. If it were ever dropped, the result is an
unread badge that silently drifts stale.

**T4 — Auth via cookie, not a URL token.** `app.sqoura.com` → `api.sqoura.com` is
same-site, so a `Secure` cookie scoped to `api.sqoura.com` is sent on the
`EventSource` request with `SameSite=Lax`. This avoids putting a JWT in a query
string where it lands in access logs. This works *because* of the current domain
layout — changing that layout breaks it.

## Phases

### Phase 0 — Containment (config only)

Raises the wall without moving it. Deliberately config-only so nothing has to be
unwound by later phases.

- `max_threads auto` in the FrankenPHP global block (stub + live file).
- Raise `ulimit nofile` for app/worker colors and the edge (see S2).
- Dedicated `rate_limit` zone for `/api/notifications/stream`.
- Upstream the S1 drift into the stub in the same pass, since it is the same file.

### Phase 1 — `vortos-sse`: transport seam

- `RealtimeTransportInterface` — the one thing app code depends on.
- `MercureTransport` (default) and `SseStream` demoted to an explicitly degraded
  local/dev fallback driver.
- App code must not be able to tell which driver is live.

### Phase 2 — `vortos-sse`: publisher + scoped subscriber tokens

- Publish the ping to the hub at the moment a notification is written or an authz
  version bumps — a millisecond POST, not a 30-second hold.
- Mint subscriber JWTs scoped to a **single per-user topic**
  (`/users/{id}/notifications`) so a token can never subscribe to another
  tenant's channel. This is the security-critical piece; it sits next to
  `vortos-auth`, not in the app.
- Keep `RealtimeSignalInterface` — the version store still backs the fallback
  driver and the payload.

### Phase 3 — `vortos-docker`: hub Caddyfile block

- `mercure` directive using the `local` transport (no disk, no backup surface).
- Publisher/subscriber JWT keys sourced from env, not committed.

### Phase 4 — `squaura-backend`: app wiring

- `StreamNotificationsController` returns hub URL + scoped token instead of streaming.
- Notification writes and `AuthorizationVersionStore` bumps publish via the transport.
- Declare `notification_realtime_publishes_total` in `config/metrics.php` —
  an undeclared metric DLQs the handler.
- Expose the SSE/publish counters on a scraped path so this class of saturation
  is visible next time.

### Phase 5 — `front`: EventSource client

- Replace the fetch-reader reconnect loop with a plain `EventSource`.
- Preserve refetch-on-connect (T3).
- Add jitter to the refetch to avoid a thundering herd (see Phase 6, Wall 3).

### Phase 6 — App-tier capacity (NOT DONE — planning only)

Sequenced separately because it is a different problem, and deliberately not
implemented: it needs load measurement, not more code. After Phases 0–5 the walls,
in the order they are hit:

**1. File descriptors — addressed in Phase 0.** Was 1024, now 65535 on the app,
worker, and edge containers.

**2. Postgres connections — `max_connections = 100`, 26 currently in use.**
Verified 2026-07-30. This is now the constraint most likely to bite first, and
Phase 0 moved it closer rather than further away: raising `WORKER_NUM` 4 → 8 and
enabling `max_threads auto` both increase the number of PHP threads, and each
thread holds its own connection. Rough budget at ~1–2 connections per thread plus
the worker color's consumers and the scheduler sidecar: **PHP threads must stay
under roughly 40** or the app starts failing on connection acquisition, which
looks like random 500s rather than like saturation.

Actions, in order:
  - Measure actual connections per thread under load (`pg_stat_activity` grouped
    by `application_name`) before raising thread counts again.
  - Decide between raising `max_connections` (cheap, costs memory per connection)
    and putting PgBouncer in front (correct at scale, adds a component and a
    transaction-pooling constraint on session state).
  - Do not raise `WORKER_NUM` past ~16 without doing one of the above first.

**3. The PHP worker pool — the real ceiling for real work.** Removing SSE stops
connections *squatting* on threads, but every API request still passes through the
pool. ~1000 active users at light use is ~50 req/s, which with 8 threads demands
an average under ~160ms end to end. The list endpoints, dashboard summary, and
permission checks will not all hit that. Needs profiling first, then some
combination of more CPU, more threads (bounded by wall 2), and caching.
Horizontal replicas are the honest answer at that scale and **collide with T1** —
that decision and the transport decision must be taken together.

**4. Thundering herd.** A ping tells every recipient "refetch now." Client-side
jitter shipped in Phase 5 (`REFETCH_JITTER_MS`); it matters far more at 1000 users
than at 10, and it is the reason a broadcast does not become a self-inflicted
outage.

## Status (2026-07-30)

Phases 0–5 implemented and verified; Phase 6 is planning only.

| Phase | State | Verification |
| --- | --- | --- |
| 0 — containment | Done | Caddyfile validated against FrankenPHP v2.11.4; framework tests + PHPStan clean |
| S1 — stub drift | Done | stub and live Caddyfile now byte-identical |
| 1 — transport seam | Done | 24 SSE package tests |
| 2 — publisher + tokens | Done | scoping/signing failure cases covered |
| 3 — hub Caddyfile | Done | all three env scenarios validated against the real binary |
| 4 — app wiring | Done | backend PHPStan + tests clean |
| 5 — frontend | Done | `tsc --noEmit` clean |
| 6 — capacity | Planning only | — |
| Preflight gate | Done | both disagreement directions covered |

### Required before this can deploy

1. **Framework release.** The `vortos-sse`, `vortos-docker`, and `vortos-deploy`
   changes are in the monorepo only. They need the split-release flow (monorepo
   tag → split CI → `composer update` in squaura-backend). The app's `vendor/`
   was synced locally for verification; that is not a deploy path.
2. **Reseal the env** with `VORTOS_MERCURE_JWT_SECRET` (>= 32 chars,
   `openssl rand -base64 48`), `VORTOS_MERCURE_CORS_ORIGINS=https://app.sqoura.com`,
   and `VORTOS_MERCURE_PUBLIC_URL=https://api.sqoura.com/.well-known/mercure`.
   This requires the X25519 identity, so it is an operator action.
3. The preflight gate blocks the deploy if 2 is skipped — by design.
