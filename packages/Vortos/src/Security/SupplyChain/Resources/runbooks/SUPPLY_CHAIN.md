# Supply-Chain Security Runbook

## Overview

The `vortos-security` supply-chain hardening system provides SBOM generation, artifact signing (cosign), SLSA provenance attestation, runtime CVE scanning, and deploy-time signature enforcement.

## Architecture

- **Build-time**: SBOM (syft) → scan (trivy) → CVE gate → sign (cosign keyless) → SLSA provenance → attach to manifest
- **Deploy-time**: signature verification (fail-closed) → `deploy:doctor` preflight
- **Runtime**: CVE watch (re-scan cron) → alert on new advisories → secret hygiene audit

## Drivers

| Port | Default | Real |
|------|---------|------|
| SbomGenerator | null | syft |
| ArtifactSigner | null | cosign (keyless Fulcio/Rekor) |
| VulnerabilityScanner | null | trivy |
| KevCatalogProvider | null | cisa |

## Keyless Signing (Zero Standing Secrets)

Default signer is **keyless cosign**: OIDC → Fulcio short-lived cert → Rekor transparency log. No signing key on disk. Verification by identity (issuer + SAN regex), not by key.

## CVE Gate Policy

- Fails on fixable critical OR any KEV-listed CVE (regardless of severity)
- Suppressions require a reason + expiry (self-revoke when expired)
- `requireFixAvailable`: when true, only fails if a fix is available

## Cloudflare Edge (Recommended)

Place Cloudflare in front of your application for WAF + DDoS protection:
- Enable "Under Attack Mode" for DDoS mitigation
- Configure WAF rules for OWASP top-10
- Use R2 for object storage (already Cloudflare-native)
- Rate limiting at the edge, before traffic reaches origin

## CLI Commands

```bash
security:sbom --ref=<repo@digest> --format=cyclonedx
security:scan --ref=<repo@digest>
security:scan-gate --ref=<repo@digest>          # exit!=0 on fixable critical/KEV
security:attest --build-id=<id>
security:verify --ref=<repo@digest> --policy=... # deploy/CI calls this
security:cve-watch --env=prod                    # runtime re-scan → alerts
security:secret-audit                            # stale/leaked → alerts
```

## Incident Response

1. **CVE found in prod**: `security:scan --ref=<current-image>` → assess severity
2. **Unsigned image detected**: check CI logs for signing failure; re-run pipeline
3. **KEV catalog unavailable**: system fails closed; check CISA endpoint / network egress
4. **Stale secret detected**: rotate immediately via Block 5 rotation manager
