<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Architecture;

/**
 * The single source of truth for "is this symbol a leaked provider name?", shared
 * by the {@see AgnosticismScanner} (PHPUnit front-end) and the
 * {@see \Vortos\OpsKit\PHPStan\AgnosticismRule} (PHPStan front-end) so the two can
 * never drift.
 *
 * Matching is **case-insensitive substring, per name segment**. Operating on a
 * parsed symbol (a namespace part or a class/interface identifier) rather than raw
 * text is what makes it precise: a provider name in a comment, in a doc-block, or in
 * a variable like `$github` is never a Name/Identifier node and so is never offered
 * to this matcher in the first place. Segment-substring (not whole-segment) means
 * `CaddyConfig` and `OracleVpsTarget` are flagged, not just a bare `Caddy`.
 *
 * The provider list MUST be curated to distinctive tokens (e.g. `caddy`, `posthog`)
 * — never short fragments like `ses` or `age` that occur inside ordinary words.
 */
final class ProviderNameMatcher
{
    /** @var list<string> lower-cased provider tokens */
    private array $providers;

    /**
     * The default, curated list of provider/vendor names that must never leak out of
     * a `Driver/` namespace or the composition root.
     *
     * @var list<string>
     */
    public const DEFAULT_PROVIDERS = [
        'caddy', 'traefik', 'nginx',
        'oracle', 'hetzner', 'digitalocean', 'linode',
        'dockerhub', 'ghcr', 'gitlab', 'github',
        'prometheus', 'grafana', 'datadog', 'newrelic',
        'sentry', 'glitchtip', 'posthog', 'amplitude', 'segment',
        'pagerduty', 'opsgenie', 'telegram', 'slack',
        'betterstack', 'uptimerobot',
        'cloudflare', 'paddle',
        'terraform', 'pulumi', 'kubernetes',
        'squawk', 'vault', 'cosign',
    ];

    /** @param list<string> $providers */
    public function __construct(array $providers)
    {
        $this->providers = array_map('strtolower', $providers);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_PROVIDERS);
    }

    /**
     * Returns the first provider token contained in any segment of $symbol, or null.
     *
     * @param string $symbol a (possibly namespaced) name or identifier, e.g. "App\Edge\CaddyClient"
     */
    public function match(string $symbol): ?string
    {
        foreach (explode('\\', $symbol) as $segment) {
            $lower = strtolower($segment);
            foreach ($this->providers as $provider) {
                if ($provider !== '' && str_contains($lower, $provider)) {
                    return $provider;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public function providers(): array
    {
        return $this->providers;
    }
}
