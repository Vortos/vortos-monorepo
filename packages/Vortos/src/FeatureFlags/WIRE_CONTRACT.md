# Feature Flags — Engine ↔ SDK Wire Contract

> **Single source of truth** for the JSON exchanged between the PHP engine
> (`vortos/vortos-feature-flags`) and any client SDK (canonically `@vortos/flags`,
> npm). Both sides MUST conform to this file. A change here is a contract change:
> update the engine, the SDK (`packages/feature-flags/src/types.ts`), and the
> contract test (`Tests/Http/FlagsControllerContractTest.php`) together — never one
> side silently.
>
> Status: v1 (Block 1, Phase A). Last updated 2026-06-21.

---

## 1. `GET /api/flags` — evaluation response (`FlagResponse`)

Server evaluates **everything** against the caller's context and returns only the
*resolved* result. The ruleset, segments, user lists, and unmatched payloads are
**never** sent to the client (PLATFORM §6 — PII/ruleset must not leak).

```jsonc
{
  "flags":    ["dark-mode", "new-checkout"],        // boolean flags that are ON for this context
  "variants": { "checkout-exp": "treatment-b" },    // flagName → assigned variant (control omitted)
  "payloads": { "pricing-config": { "tier": "pro" } }, // flagName → remote-config blob, ON flags only
  "version":  "v1:9f2a1c4e7b0d3a55"                 // deterministic config hash (see §3)
}
```

TypeScript (authoritative client mirror, `@vortos/flags`):

```ts
interface FlagResponse {
  flags?: string[];
  variants?: Record<string, string>;
  payloads?: Record<string, unknown>;
  version?: string;
}
```

Field rules:

| Field | Type | Rule |
|---|---|---|
| `flags` | `string[]` | names where the boolean treatment is `true`. Order not significant. |
| `variants` | `{[name]: string}` | only flags with variants whose assignment ≠ `control`. |
| `payloads` | `{[name]: json}` | only flags that are ON **and** carry a `payload`. Never unmatched. |
| `version` | `string` | always present; see §3. Used for ETag/304 (Block 16) and SSE (Block 27). |

## 2. Targeting context — `X-Vortos-Flag-Context` header

The SDK serializes `FlagTargetingContext` to JSON in this request header on both
`GET /api/flags` and exposure POSTs:

```ts
interface FlagTargetingContext {
  userId?: string;
  role?: string; roles?: string[];
  tenantId?: string; federationId?: string;
  country?: string; plan?: string;
  attributes?: Record<string, unknown>;
}
```

**Security — trust zones (load-bearing, PLATFORM §6).** This header is
attacker-controlled. The server MUST NOT trust entitlement-bearing fields from it.

- **Trusted zone** (server derives from the authenticated identity, ignores the
  header): `userId`, `roles`, `tenantId`, `plan`, `federationId`, entitlements.
- **Untrusted zone** (accepted from the header — client legitimately owns them, they
  grant nothing): `country`, `deviceId`, `sessionId`, UI `attributes`.

`FlagContext` keeps these zones separate (`trusted` / `untrusted`). Rules read the
trusted zone by default; reading untrusted is explicit and is forbidden for
`permission`-kind flags. Resolver-side population from identity is enforced in
Block 9; the structure exists from Block 1 so no block can take the insecure
"trust the header" shortcut.

## 3. `version` — deterministic config hash

```
version = "v1:" + first16hex( xxh3( json( canonical ) ) )
```

`canonical` = every flag's `toArray()`, sorted ascending by `name`. The same
configuration produces the same `version` on every node (no per-node salt, no
wall-clock input beyond the flags' own `updated_at`). It changes whenever any flag's
persisted state changes. Pinned by `Tests/FlagValueTest` / `Tests/FlagRegistryTest`;
**do not alter the algorithm without bumping the `v1:` prefix.**

## 4. Value types

A flag declares a `value_type`: `bool | string | number | json`.

| `value_type` | Wire channel | On value | Off / unmatched / error |
|---|---|---|---|
| `bool` | `flags[]` | `true` | `default_value` (typically `false`) |
| `string` | `variants` (via Block 5) | variant value | `default_value` |
| `number` | server-side only | `default_value` until variants | `default_value` |
| `json` | `payloads` | `payload` | `default_value` |

`default_value` is the **guaranteed safe fallback** — returned whenever the flag is
off, unmatched, or evaluation cannot complete. The engine never throws into a request
on the evaluation path. JSON values are bounded: **≤ 32 KiB**, **≤ 32 levels deep**
(rejected at write).

## 5. Exposure ingestion — `POST {exposureEndpoint}` (Block 8)

The SDK POSTs (with the same `X-Vortos-Flag-Context` header):

```ts
interface ExposureEvent { name: string; variant?: string; timestamp: number; }
```

Endpoint + OTel ingestion land in Block 8; the shape is pinned here now so both sides
agree from the start.
