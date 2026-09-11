# The first-party exposure ledger

Vortos evaluates flags. PostHog measures them — but only the subjects who consented to
analytics, and only as many as the sample rate lets through. This ledger is the other half:
a complete, unsampled, pseudonymous record of every exposure, held in your own database, and
the thing an experiment readout is actually computed from.

Both streams observe the *same* exposures through the same `ExposureObserverInterface` seam.
They differ in who is allowed to see the data and how much of it survives.

| | `AnalyticsExposureObserver` | `LedgerExposureObserver` |
|---|---|---|
| Destination | PostHog (third party) | your Postgres |
| Consent | gated | not gated |
| Sampling | `ANALYTICS_FLAG_EXPOSURE_SAMPLE_RATE` | none |
| Identity | analytics distinct id | keyed HMAC |
| Used for | exploration, Usage tab | experiment readouts |

## Why a consent-gated stream cannot answer an experiment

Consent is not missing at random. It correlates with tenant size, jurisdiction, and privacy
posture — and each of those correlates with the behaviour being measured. A readout computed
over consenting subjects is therefore not a smaller answer to your question; it is a precise
answer to a different one. The same applies to identity-based sampling: it shrinks the
population non-randomly.

That is the entire reason this exists. Everything else here is plumbing.

## Turning it on

```dotenv
FEATURE_FLAGS_LEDGER=1
FEATURE_FLAGS_LEDGER_PEPPER=<openssl rand -hex 32>
FEATURE_FLAGS_LEDGER_RETENTION_DAYS=30
FEATURE_FLAGS_LEDGER_ROLLUP_RETENTION_DAYS=730
FEATURE_FLAGS_LEDGER_GROUP_TYPE=organization
```

`FEATURE_FLAGS_LEDGER_PEPPER` is **required** and validated at container-compile time. A
deployment that enables the ledger without one refuses to boot, on purpose: a ledger that
starts and then stores weakly-hashed identities fails invisibly, and by the time anyone
notices, the data is written.

Put the pepper in your **sealed secret store**, not `.env.prod` — that file is rewritten on
every deploy.

### Rotating the pepper

Rotation severs subject identity: the same person becomes a new subject. Anything running
across the rotation sees them in both arms and its readout is void. Rotate *between*
experiments, never during one.

## What it costs

The grain is **one row per subject, per flag, per variant, per UTC day** — not per
evaluation. `isEnabled()` is called freely on the request path, and a per-evaluation ledger
would be the highest-volume table in the schema while answering nothing extra: experiment
statistics count subjects, not calls.

At ~150 bytes per row including the index:

```
rows/day  = daily active subjects × flags evaluated
table     = rows/day × FEATURE_FLAGS_LEDGER_RETENTION_DAYS
rollup    = days × flags × variants × orgs   (grows with tenants, not traffic)
```

A thousand daily actives across twenty flags is ~20k rows/day, ~90 MB at 30 days. The rollup
is two orders of magnitude smaller and is what survives to answer year-over-year questions.

## What it costs the request

Nothing measurable. `LedgerExposureObserver` does no I/O — it appends to an array.
`ExposureLedgerFlusher` runs as terminable middleware, after the response has been sent, and
does the Redis dedupe and one batched `INSERT` there.

> **The tag on the flusher is load-bearing.** `RegisterTerminablePass` finds terminable
> middleware by scanning definitions for the interface, but nothing injects the flusher, so
> `RemoveUnusedDefinitionsPass` deletes it as unreferenced *before* that scan runs. The
> `vortos.http.terminable` tag exists only to keep the definition alive long enough to be
> discovered. Without it the ledger compiles, boots, buffers every exposure, and writes none.

## Running it

Nightly, in-container:

```bash
php bin/console vortos:flags:exposures:rollup
```

Rolls every pending day into the aggregate, then prunes both tables. Idempotent — a double
run is a no-op, not a double deletion. It always rolls forward before it deletes, so a
shortened retention window or a missed night cannot turn into a permanent hole.

Reading an experiment out:

```bash
php bin/console vortos:flags:experiment:readout <flag> <metric> \
    --from=2026-09-01 --to=2026-09-14 [--control=false] [--json]
```

The window must fit inside `FEATURE_FLAGS_LEDGER_RETENTION_DAYS`. The command refuses an
older one rather than silently returning a truncated result — the subjects a partial window
drops are the earliest, who are systematically unlike the latest.

### Supplying the outcome

The framework owns exposures; only your application knows what "converted" means. Bind
`OutcomeSourceInterface`:

```php
final class SignupOutcomes implements OutcomeSourceInterface
{
    public function convertedSubjects(string $metric, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        // return raw analytics identities — the readout pseudonymises them itself
    }

    public function availableMetrics(): array
    {
        return ['signup_completed'];
    }
}
```

Return **raw** user ids, each at most once. The pepper stays inside the framework, so your
code cannot accidentally hash with the wrong one — which would produce an experiment where
nobody ever converts and no error anywhere.

## How to read a result honestly

The readout reports warnings with the same prominence as the numbers, because a readout is
most dangerous when it is small, early, or contaminated — and all three look perfectly
ordinary.

- **Sample too small.** Below five expected conversions and non-conversions per arm the
  normal approximation does not hold. `isSignificant()` returns false regardless of the
  p-value, because a small sample clears p < 0.05 by chance far more often than the number
  implies.
- **Window includes today.** Peeking at a running experiment and stopping when it looks
  significant inflates the false-positive rate well past 5%. The default window ends
  yesterday.
- **Subjects in more than one arm.** They are excluded from both and counted. A large count
  means the flag did not hold still; discard the result rather than interpret it.

The test is a pooled two-proportion z-test — the same one a frequentist A/B tool runs, chosen
so the numbers are comparable to ones a reader already knows how to interpret. There is no
sequential testing, no Bayesian posterior, and no multiple-comparison correction. Each would
change how a result must be read, and shipping them silently inside a function called
"significance" would be worse than not shipping them.

## Metrics

- `vortos_flags_ledger_rows_total` — rows appended. Shows the pipeline is alive.
- `vortos_flags_ledger_write_failures_total` — **alert on this.** A failing ledger does not
  make a readout unavailable, it makes it wrong: the missing subjects are the ones whose
  writes failed, which is not a random subset.
