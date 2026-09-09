# Feature flags in PostHog

Vortos evaluates flags. PostHog measures them. This document is how the two are joined, and
what an operator has to set up.

Nothing here hands evaluation to PostHog. Vortos remains the only thing that decides who gets
a flag; PostHog gets told what happened, in the shapes its UI already knows how to read.

## What PostHog needs, and why it needs all of it

PostHog reads flag data through three unrelated conventions. Wiring only one leaves most of
the product empty, which is why this looks like more moving parts than it should:

| Convention | What it powers | Produced by |
|---|---|---|
| `$feature_flag_called` with `$feature_flag` / `$feature_flag_response` | a flag's **Usage** tab | the exposure bridge |
| `$feature/<key>` on **any** event | experiment results, and "break this insight down by flag" | flag attribution on every event |
| `$active_feature_flags` person property | cohorts ("users in the new-checkout flag") | flag attribution, on `identify` |
| a flag **entity** in the project | the Feature Flags list; attaching an Experiment | the definition sync |

`$feature/<key>` is the one people miss. PostHog's documented convention for flags evaluated
*outside* PostHog is that the variant travels on the event you are measuring — because the
metric you care about (a signup, a payment) lives on a different event from the exposure, and
nothing can join the two after the fact. An exposure event on its own can tell you a flag was
called. It can never tell you whether the flag moved anything.

A flag entity is likewise not optional: exposure events create no entity, so without the sync
a flag is invisible in the Feature Flags UI and no Experiment can be attached to it.

## Where exposures come from

Both sides now produce them.

- **Client SDK** — `@vortos/flags` POSTs to `/api/flags/exposures` when a component reads a
  flag. Requires `exposureEndpoint` on the provider; see that package's README.
- **Server-side** — `ExposureReportingFlagRegistry` reports every `isEnabled()` / `variant()`
  call, including `#[RequiresFlag]` and the route middleware.

The server half is worth stating plainly because its absence was invisible: before it existed,
a flag that only gated backend behaviour recorded a Prometheus counter and told the analytics
backend nothing at all, so it appeared to have zero traffic and could not be measured or
experimented on no matter how much it was used.

`allForContext()` is deliberately **not** an exposure. It is the bulk bootstrap the client SDK
calls to fill its cache; counting it would report every flag in the project on every page load.

## Configuration

### Ingestion (already present if you send product analytics at all)

```dotenv
ANALYTICS_DRIVER=posthog
ANALYTICS_ENABLED=true
POSTHOG_HOST=https://us.i.posthog.com
POSTHOG_PROJECT_API_KEY=phc_...        # write-only ingestion key
```

### Flag analytics

```dotenv
ANALYTICS_FLAG_EXPOSURE_BRIDGE=1       # emit exposures to the analytics backend
ANALYTICS_FLAG_EXPOSURE_SAMPLE_RATE=1.0
ANALYTICS_FLAG_ATTRIBUTION=1           # $feature/<key> on every event (defaults to the bridge's value)
```

`ANALYTICS_FLAG_EXPOSURE_SAMPLE_RATE` defaults to `0.1`. Ten percent is a sensible default for
a firehose and a bad one for an experiment: at low traffic it will not reach significance in
any useful time. Set it to `1.0` unless volume actually demands otherwise. Sampling is
deterministic per `(subject, flag)`, so a sampled-out user is *consistently* absent rather than
flickering between arms — which would bias the result rather than just shrink it.

Escape hatches, rarely needed:

```dotenv
FEATURE_FLAGS_SERVER_EXPOSURES=0             # stop reporting server-side evaluations
FEATURE_FLAGS_EXPOSURE_GROUP_MAP=organization:tenantId,team:accountId
FEATURE_FLAGS_MAX_EXPOSURES_PER_REQUEST=500
FEATURE_FLAGS_MAX_ACTIVE_PER_REQUEST=200
```

### Definition sync (flags appearing in PostHog by themselves)

This needs a **different and broader credential** than everything above. The management API
does not accept the project ingestion key:

```dotenv
POSTHOG_PROJECT_ID=12345
POSTHOG_PERSONAL_API_KEY=phx_...       # personal API key, scope: feature_flag:write
POSTHOG_FLAG_SYNC=1                    # mirror on every flag change
```

Create the key in PostHog under *Personal API keys*, scoped to `feature_flag:write` and to the
one project. Treat it as a secret of a different class from the ingestion key — it can write to
your PostHog project, and it belongs in the sealed store, never in a file a deploy rewrites.

Then backfill the flags that already exist:

```bash
vortos:flags:posthog:sync --dry-run    # report what would change
vortos:flags:posthog:sync              # do it
```

The command is safe to re-run; flags that already match are left alone. It is also the
reconciliation backstop — schedule it if you want a guarantee rather than best-effort, since
the on-change listener is fail-soft by design.

## What the sync will and will not do

- Creates flags that are missing, and patches ones whose mirrored fields drifted.
- Tags every mirrored flag `vortos`, plus `kind:`, `project:`, `env:` and `owner:` tags.
- **Never deletes.** A flag that exists only in PostHog is left alone.
- **Never overwrites a flag PostHog owns.** If a key exists without the `vortos` tag, the sync
  reports it as a failure rather than replacing someone's hand-made configuration with an inert
  mirror.
- Mirrors release conditions as a **0% rollout**, always. If anything ever did ask PostHog to
  evaluate one of these, the honest answer it gets is "off" — not an accidental launch to
  everybody. `evaluation_runtime` is `server` for the same reason.
- Mirrors variant keys faithfully (rescaled to total 100, which PostHog requires), because
  PostHog needs them to break experiment results down by variant.

## Privacy

Two things gate flag analytics, and both are deliberate:

- **Consent.** The analytics consent resolver applies to exposures exactly as it does to every
  other event. An org that has not granted analytics consent contributes no exposures — so
  experiment readouts are biased toward consenting orgs. Know that before trusting a number.
- **Allowlists.** `ANALYTICS_EVENT_PROPERTY_ALLOWLIST` still governs app-authored properties.
  The framework's own control properties (`flag`, `variant`, `exposure_source`, and the
  attribution map) are *reserved* and survive it structurally — see `ReservedProperties`. They
  do not bypass the consent gate or the PII redactor.

Group associations on an exposure are read only from the flag context's **trusted** zone, so a
client editing `X-Vortos-Flag-Context` cannot attribute its exposures to another tenant.
