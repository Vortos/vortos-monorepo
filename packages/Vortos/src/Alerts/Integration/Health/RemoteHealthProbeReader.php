<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

use Throwable;

/**
 * Reads another node's Monitoring probe results over HTTP.
 *
 * WHY THIS EXISTS. Alert evaluation runs on exactly one node — the scheduler — but a health probe
 * can only be answered by the node that owns the dependency. {@see HealthProbeAlertSource} used to
 * call `$probe->check()` locally, which silently assumes those are the same node. They are not.
 *
 * On this deployment the backup sidecar evaluates every alert, and it holds backup-only object-store
 * credentials that are deliberately denied the application's user-content bucket. Its S3 probe
 * therefore returned 403, the evaluator read that as "the object store is unreachable", and
 * `object-store-unreachable` paged on-call every hour for over a day — while the application, the
 * node that actually serves uploads, reported the same store healthy throughout. The alarm was
 * describing the credentials of the machine asking, not the health of the thing asked about.
 *
 * So a delegated probe is READ FROM ITS OWNER rather than re-answered locally.
 *
 * Candidates rather than one URL, because the owner is a blue/green pair and which colour is live
 * changes on every deploy. The first that answers wins; there is only ever one running, and
 * resolving "which colour" here would duplicate what the edge already decides.
 *
 * UNREACHABLE IS NOT UNHEALTHY. If no candidate answers, this returns null and the caller skips the
 * probe. That is deliberate: an application no node can reach is an outage, and it is already
 * covered by the external uptime journey — the one check that does not run on this box. Alerting
 * again from here would double-page a single incident, and would page for a momentary blip on the
 * sidecar's own network besides. Conflating "I cannot see it" with "it is broken" is the exact
 * failure this class was written to remove.
 */
final class RemoteHealthProbeReader
{
    /**
     * @param list<string> $baseUrls candidate origins of the owning node, tried in order
     */
    public function __construct(
        private readonly array $baseUrls,
        private readonly string $token,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function isConfigured(): bool
    {
        return $this->baseUrls !== [] && $this->token !== '';
    }

    /**
     * Every Monitoring probe the owner reports, keyed by its check name.
     *
     * @return array<string, RemoteProbeResult>|null null when no candidate answered at all
     */
    public function read(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        foreach ($this->baseUrls as $baseUrl) {
            $body = $this->fetch(rtrim($baseUrl, '/') . '/health/detail?mode=monitor');

            if ($body === null) {
                continue;
            }

            $decoded = json_decode($body, true);
            if (!\is_array($decoded) || !\is_array($decoded['checks'] ?? null)) {
                // Answered, but not with a health report — a proxy error page, most likely. Try the
                // next candidate rather than trusting a body that is not the contract.
                continue;
            }

            $results = [];

            foreach ($decoded['checks'] as $name => $check) {
                if (!\is_string($name) || !\is_array($check)) {
                    continue;
                }

                $results[$name] = new RemoteProbeResult(
                    name: $name,
                    status: \is_string($check['status'] ?? null) ? $check['status'] : 'unknown',
                    detail: \is_string($check['error'] ?? null) ? $check['error'] : '',
                );
            }

            return $results;
        }

        return null;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Health-Token: ' . $this->token,
            'Accept: application/json',
        ]);

        try {
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } catch (Throwable) {
            return null;
        }

        // 401 means this node's token does not match the owner's — a misconfiguration, not a health
        // signal, and emphatically not evidence the dependency is down. Treated as "no answer" so it
        // can never masquerade as one.
        if ($body === false || $status !== 200) {
            return null;
        }

        return (string) $body;
    }
}
