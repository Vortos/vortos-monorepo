<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions;

use Vortos\Pipeline\Verification\ActionRefResolverInterface;

/**
 * Resolves refs against the public GitHub REST API (`GET /repos/{o}/{r}/commits/{ref}`), which
 * returns the resolved commit for a SHA or tag and 404/422 for a ref that does not exist.
 *
 * A `GITHUB_TOKEN` (if present in the environment) is sent to lift the low unauthenticated rate
 * limit; the endpoint works unauthenticated for public repos too.
 */
final class GitHubApiActionRefResolver implements ActionRefResolverInterface
{
    public function __construct(
        private readonly string $apiBase = 'https://api.github.com',
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    public function resolve(string $owner, string $repo, string $ref): ?string
    {
        $url = sprintf('%s/repos/%s/%s/commits/%s', rtrim($this->apiBase, '/'), rawurlencode($owner), rawurlencode($repo), rawurlencode($ref));

        $headers = [
            'User-Agent: vortos-pipeline-actions-verify',
            'Accept: application/vnd.github+json',
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
        if ($status !== 200) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['sha']) && is_string($decoded['sha'])) {
            return $decoded['sha'];
        }

        return null;
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
