# Authorization decision matrix

Reference for the scope-aware authorization model. The engine denies by default and
only ever reaches the policy/scope layer **after** every gate below has passed:

1. permission is registered (`UnknownPermission` otherwise)
2. permission string is well-formed (`InvalidPermissionFormat`)
3. identity is authenticated (`Unauthenticated`)
4. identity is not on the emergency deny list (`EmergencyDenied`)
5. authz-version claim is not stale (`StaleToken`)
6. RBAC `has()` grants the permission (`MissingPermission`)
7. if a scoped binding was supplied, the scoped store satisfies it (`ScopedPermissionDenied`)

A policy therefore can only ever **restrict** — it can never re-authorize a permission
RBAC denied.

## No-policy outcome (after all gates pass)

| Scope enforcement kind | Binding enforced this request? | Outcome | Reason |
|---|---|---|---|
| `SelfSufficient` (`any`, `global`) | n/a | **ALLOW** | `RbacAuthoritative` |
| `Containment` (app-mapped, e.g. `org`, `team`) | yes | **ALLOW** | `ScopeSatisfied` |
| `Containment` | no | **DENY** | `PolicyOrScopeRequired` |
| `Ownership` (`own`) | yes or no | **DENY** | `PolicyOrScopeRequired` |
| unclassified scope (e.g. `federation` until mapped) | yes or no | **DENY** | `PolicyOrScopeRequired` |

Overrides (checked before scope kind, only when no policy is registered):

| Permission flag | Outcome | Reason |
|---|---|---|
| `policyRequired: true` | **DENY** | `PolicyRequired` |
| `selfEnforced: true` | **ALLOW** | `ExternallyEnforced` |

`policyRequired` takes precedence over `selfEnforced`.

## With-policy outcome

| Policy result | Outcome | Reason |
|---|---|---|
| `true` / `PolicyDecision::allow()` | **ALLOW** | `Allowed` |
| `false` | **DENY** | `ResourceDenied` |
| `PolicyDecision::deny('x')` | **DENY** | `x` (custom, auditable) |

## The two invariants

1. **Containment never satisfies ownership.** A satisfied `org` binding on an `.own`
   permission still denies — record ownership is provable only by a policy. This is the
   horizontal privilege-escalation guard.
2. **A policy can only restrict.** Allow is unreachable unless RBAC `has()` already
   passed; an allow-all policy cannot grant a permission RBAC denies.

## Scope classification is app-owned

The framework defaults only the universal names: `any`/`global` => `SelfSufficient`,
`own` => `Ownership`. Every other scope (`org`, `team`, `federation`, `tenant`, …) is an
app concept, classified via `VortosAuthorizationConfig::scopeEnforcement([...])`. Any
unclassified scope falls to `Ownership` (fail closed).

## Tests

- `ScopeAwareDecisionTest` — the worked security matrix incl. the privesc guard.
- `DecisionMatrixPropertyTest` — exhaustive enumeration of the no-policy matrix asserting
  no unintended allow.
- `ScopeEnforcementClassifierTest` — classification + fail-closed defaults.
- `OwnershipResolutionTest` — `owns()` / `#[Owner]` / `OwnerResolverInterface` / `PolicyDecision`.
