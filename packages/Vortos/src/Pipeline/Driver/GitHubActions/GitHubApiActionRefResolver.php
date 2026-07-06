<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions;

use Vortos\Pipeline\Verification\ActionRefResolverInterface;
use Vortos\Pipeline\Verification\ActionRuntimeResolverInterface;

/**
 * Resolves refs against the public GitHub REST API (`GET /repos/{o}/{r}/commits/{ref}`), which
 * returns the resolved commit for a SHA or tag and 404/422 for a ref that does not exist. It also
 * reads an action's `runs.using` runtime from its `action.yml` at a given ref (contents API).
 *
 * A `GITHUB_TOKEN` (if present in the environment) is sent to lift the low unauthenticated rate
 * limit; the endpoints work unauthenticated for public repos too.
 */
final class GitHubApiActionRefResolver implements ActionRefResolverInterface, ActionRuntimeResolverInterface
{
    public function __construct(
        private readonly string $apiBase = 'https://api.github.com',
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    public function resolve(string $owner, string $repo, string $ref): ?string
    {
        $url = sprintf('%s/repos/%s/%s/commits/%s', rtrim($this->apiBase, '/'), rawurlencode($owner), rawurlencode($repo), rawurlencode($ref));

        $body = $this->httpGet($url, 'application/vnd.github+json');
        if ($body === null) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['sha']) && is_string($decoded['sha'])) {
            return $decoded['sha'];
        }

        return null;
    }

    public function runtime(string $owner, string $repo, string $ref): ?string
    {
        foreach (['action.yml', 'action.yaml'] as $file) {
            $url = sprintf(
                '%s/repos/%s/%s/contents/%s?ref=%s',
                rtrim($this->apiBase, '/'),
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode($file),
                rawurlencode($ref),
            );

            // The raw media type returns the file bytes directly (not a base64 JSON envelope).
            $body = $this->httpGet($url, 'application/vnd.github.raw');
            if ($body === null) {
                continue;
            }

            $using = $this->parseUsing($body);
            if ($using !== null) {
                return $using;
            }
        }

        return null;
    }

    /** Extract `runs.using` from an action manifest without a full YAML parser. */
    private function parseUsing(string $manifest): ?string
    {
        if (preg_match('/^\s*using\s*:\s*["\']?([A-Za-z0-9._-]+)["\']?/mi', $manifest, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function httpGet(string $url, string $accept): ?string
    {
        $headers = [
            'User-Agent: vortos-pipeline-actions-verify',
            'Accept: ' . $accept,
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $token = getenv('GITHUB_TOKEN');
        if (is_string($token) && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $status = $this->statusFromHeaders($http_response_header ?? []);

        return $status === 200 ? $body : null;
    }

    /** @param list<string> $headers */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
