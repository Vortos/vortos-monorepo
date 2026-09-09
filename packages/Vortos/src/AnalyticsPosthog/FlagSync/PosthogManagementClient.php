<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

use Throwable;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfGuard;

/**
 * Thin client for PostHog's **management** API (`/api/projects/{id}/feature_flags/`), used
 * only to mirror flag definitions so they exist as real entities in PostHog's UI.
 *
 * ## Why this is separate from the ingestion transport
 *
 * `CurlAnalyticsTransport` is POST-only and returns a bare bool, which is exactly right for
 * fire-and-forget event ingestion and useless here: reconciling definitions needs GET and
 * PATCH, needs the response body, and needs to distinguish "already exists" from "your key
 * is wrong". This is also a different **credential** — the ingestion key is a write-only
 * project key, while the management API requires a *personal* API key scoped
 * `feature_flag:write`, which is a materially broader secret and is deliberately read from a
 * separate environment variable that nothing on the request path touches.
 *
 * Egress is SSRF-guarded and hard-bounded, and the key is read at call time and never stored
 * on the instance, logged, or included in an exception message.
 */
final class PosthogManagementClient implements PosthogFlagApiInterface
{
    public const DEFAULT_HOST = 'https://us.i.posthog.com';

    /** PostHog caps page size; we page until exhausted rather than assuming one page. */
    private const PAGE_LIMIT = 100;

    /** A runaway pagination loop is a bug, not a large project. */
    private const MAX_PAGES = 100;

    public function __construct(
        private readonly string $hostEnvVar = 'POSTHOG_HOST',
        private readonly string $projectIdEnvVar = 'POSTHOG_PROJECT_ID',
        private readonly string $personalApiKeyEnvVar = 'POSTHOG_PERSONAL_API_KEY',
        private readonly int $connectTimeoutSeconds = 3,
        private readonly int $totalTimeoutSeconds = 15,
    ) {}

    /** True when both the project id and a personal API key are present. */
    public function isConfigured(): bool
    {
        return $this->env($this->projectIdEnvVar) !== null
            && $this->env($this->personalApiKeyEnvVar) !== null;
    }

    /**
     * Every flag PostHog already knows about, keyed by flag key.
     *
     * @return array<string,array<string,mixed>>
     * @throws PosthogApiException
     */
    public function listFlags(): array
    {
        $flags = [];
        $offset = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->request('GET', $this->flagsPath() . '?limit=' . self::PAGE_LIMIT . '&offset=' . $offset);

            $results = $response['results'] ?? null;
            if (!is_array($results)) {
                throw new PosthogApiException('PostHog returned no `results` array when listing feature flags.');
            }

            foreach ($results as $flag) {
                if (is_array($flag) && isset($flag['key']) && is_string($flag['key'])) {
                    $flags[$flag['key']] = $flag;
                }
            }

            if (count($results) < self::PAGE_LIMIT || ($response['next'] ?? null) === null) {
                return $flags;
            }

            $offset += self::PAGE_LIMIT;
        }

        throw new PosthogApiException('Aborted listing PostHog feature flags after ' . self::MAX_PAGES . ' pages.');
    }

    /**
     * @param array<string,mixed> $payload
     * @return int the new flag's PostHog id
     * @throws PosthogApiException
     */
    public function createFlag(array $payload): int
    {
        $response = $this->request('POST', $this->flagsPath(), $payload);

        $id = $response['id'] ?? null;
        if (!is_int($id)) {
            throw new PosthogApiException('PostHog accepted the flag but returned no numeric id.');
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $payload
     * @throws PosthogApiException
     */
    public function updateFlag(int $id, array $payload): void
    {
        $this->request('PATCH', $this->flagsPath() . $id . '/', $payload);
    }

    private function flagsPath(): string
    {
        $projectId = $this->env($this->projectIdEnvVar);
        if ($projectId === null) {
            throw new PosthogApiException("PostHog project id is not configured ({$this->projectIdEnvVar}).");
        }

        return '/api/projects/' . rawurlencode($projectId) . '/feature_flags/';
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     * @throws PosthogApiException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $apiKey = $this->env($this->personalApiKeyEnvVar);
        if ($apiKey === null) {
            throw new PosthogApiException(
                "PostHog personal API key is not configured ({$this->personalApiKeyEnvVar}). "
                . 'The management API needs a personal key with the `feature_flag:write` scope; '
                . 'the project ingestion key cannot be used here.',
            );
        }

        if (!function_exists('curl_init')) {
            throw new PosthogApiException('The curl extension is required for the PostHog management API.');
        }

        $host = rtrim($this->env($this->hostEnvVar) ?? self::DEFAULT_HOST, '/');
        $url  = $host . $path;

        $this->assertSafe($url);

        $handle = curl_init();
        if ($handle === false) {
            throw new PosthogApiException('Could not initialise a curl handle.');
        }

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT        => $this->totalTimeoutSeconds,
            // Deliberately NOT FAILONERROR: we want PostHog's error body, which is the only
            // thing that explains a 400 on a rejected payload.
            CURLOPT_FAILONERROR    => false,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $encoded = json_encode($body);
            if ($encoded === false) {
                curl_close($handle);
                throw new PosthogApiException('Could not encode the PostHog request body as JSON.');
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $raw    = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new PosthogApiException('PostHog management request failed: ' . ($error !== '' ? $error : 'unknown transport error'));
        }

        $raw = (string) $raw;

        if ($status === 401 || $status === 403) {
            throw new PosthogApiException(
                'PostHog rejected the personal API key. It must be valid and carry the `feature_flag:write` scope',
                $status,
                $raw,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new PosthogApiException('PostHog management request failed', $status, $raw);
        }

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @throws PosthogApiException when the destination is unsafe */
    private function assertSafe(string $url): void
    {
        try {
            if (class_exists(SsrfGuard::class)) {
                (new SsrfGuard())->assertSafe($url);

                return;
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'https') {
                throw new PosthogApiException("PostHog host must use https, got '{$scheme}'.");
            }
        } catch (PosthogApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PosthogApiException('PostHog host rejected by the egress guard: ' . $e->getMessage());
        }
    }

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
