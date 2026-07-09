<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Vortos\Deploy\Cutover\Edge\EdgeBaseConfig;
use Vortos\Deploy\Cutover\Edge\EdgeConfigAdapterInterface;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;
use Vortos\Deploy\Exception\EdgeBaseConfigException;

/**
 * Adapts an operator's Caddyfile to structured Caddy JSON using Caddy's own parser.
 *
 * The deploy one-shot is the PHP app image and has no caddy binary, so the adapt runs in a throwaway,
 * digest-pinned caddy container ("docker run --rm -i <image> caddy adapt"). Two properties matter:
 *
 *  - It works even when the edge is DOWN — which is the exact incident scenario the preflight doctor
 *    must validate for. The admin API "POST /adapt" (see {@see CaddyAdminClient}) is only usable while
 *    the edge is up, so it is a secondary runtime path, never the preflight one.
 *  - It pins the caddy image by digest so preflight adapts with the SAME parser prod loads with. A
 *    version skew between "what was validated" and "what is served" is itself treated as drift.
 *
 * Hardening: the container runs with "--network none" (adapt is pure text parsing and needs no
 * egress); input and output are size-bounded (DoS guard); and a parse failure NEVER echoes the file
 * body — only a line number — because a Caddyfile routinely carries secrets (basicauth hashes,
 * forward_auth tokens).
 */
final class CaddyfileAdapter implements EdgeConfigAdapterInterface
{
    public const DEFAULT_MAX_OUTPUT_BYTES = 4_194_304; // 4 MiB of adapted JSON is already generous.

    public function __construct(
        private readonly string $adaptImage,
        private readonly ?SshTransportInterface $sshTransport = null,
        private readonly ?CommandRunnerInterface $localRunner = null,
        private readonly int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private readonly float $timeoutSeconds = 30.0,
    ) {}

    /**
     * Adapt (or, for a JSON base, parse) the base config into a Caddy JSON tree.
     *
     * @return array<string, mixed>
     */
    public function adapt(EdgeBaseConfig $base): array
    {
        if (!$base->format->requiresAdapt()) {
            return $this->parseJson($base);
        }

        // The "--config -" flag makes caddy adapt read the Caddyfile from STDIN. Without it, caddy
        // ignores the piped stdin, looks for a Caddyfile in the container's CWD, finds none, and fails
        // with "input file required ... use --config flag" — so the adapt (and thus the whole edge
        // base-config gate + cutover) never worked against a real caddy binary. The -i flag above keeps
        // STDIN open for the pipe.
        $argv = [
            'docker', 'run', '--rm', '-i', '--network', 'none',
            $this->adaptImage,
            'caddy', 'adapt', '--adapter', 'caddyfile', '--config', '-',
        ];

        $result = $this->sshTransport !== null
            ? $this->sshTransport->run(new RemoteCommand($argv, stdin: $base->contents))
            : $this->requireLocalRunner()->run($argv, $base->contents, $this->timeoutSeconds);

        if ($result->exitCode !== 0) {
            throw EdgeBaseConfigException::adaptFailed($this->summarizeError($result->stderr));
        }

        $json = $result->stdout;
        if (\strlen($json) > $this->maxOutputBytes) {
            throw EdgeBaseConfigException::adaptOutputTooLarge(\strlen($json), $this->maxOutputBytes);
        }

        return $this->decode($json, $base->path);
    }

    /** @return array<string, mixed> */
    private function parseJson(EdgeBaseConfig $base): array
    {
        if ($base->byteLength() > $this->maxOutputBytes) {
            throw EdgeBaseConfigException::adaptOutputTooLarge($base->byteLength(), $this->maxOutputBytes);
        }

        return $this->decode($base->contents, $base->path);
    }

    /** @return array<string, mixed> */
    private function decode(string $json, string $path): array
    {
        try {
            // Decode objects as \stdClass (NOT assoc arrays) so an EMPTY JSON object is preserved. An
            // empty object decoded with JSON_OBJECT_AS_ARRAY becomes an empty PHP array [], which
            // re-encodes as a JSON array [] — caddy then rejects it (e.g. an encode-gzip directive adapts to
            // {"gzip":{}}; corrupted to {"gzip":[]} it fails "cannot unmarshal array into caddygzip.Gzip"
            // at /load). normalizeNode() then turns non-empty objects into the assoc arrays the merge
            // walks, while leaving empty objects as \stdClass leaves (which json_encode re-emits as {}).
            $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw EdgeBaseConfigException::invalidJson($path, $e->getMessage());
        }

        if (!$decoded instanceof \stdClass) {
            throw EdgeBaseConfigException::invalidJson($path, 'top-level value is not an object');
        }

        $normalized = $this->normalizeNode($decoded);

        /** @var array<string, mixed> $normalized */
        return is_array($normalized) ? $normalized : [];
    }

    /**
     * Recursively convert a decoded JSON tree so the array-based merge can walk maps as PHP arrays
     * WITHOUT losing the object/array distinction on re-encode: non-empty objects become associative
     * arrays; empty objects stay \stdClass (re-encode as {}); arrays stay arrays. Empty objects are
     * always leaves the merge never descends into, so \stdClass leaves are inert to the merge.
     */
    private function normalizeNode(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            $map = (array) $node;

            return $map === [] ? new \stdClass() : array_map($this->normalizeNode(...), $map);
        }

        if (is_array($node)) {
            return array_map($this->normalizeNode(...), $node);
        }

        return $node;
    }

    private function requireLocalRunner(): CommandRunnerInterface
    {
        if ($this->localRunner === null) {
            throw EdgeBaseConfigException::adaptFailed('no adapt transport configured (neither SSH nor local runner)');
        }

        return $this->localRunner;
    }

    /**
     * Produce a secret-free summary of a caddy adapt failure. Caddy prints the offending source
     * context to stderr (which can include a directive body holding a secret), so we keep ONLY the
     * "path:line" location tokens and a fixed remediation hint — never the echoed content.
     */
    private function summarizeError(string $stderr): string
    {
        if (preg_match('/:(\d+):(\d+)/', $stderr, $m) === 1) {
            return sprintf('parse error near line %d (validate the Caddyfile locally to see details)', (int) $m[1]);
        }

        if (preg_match('/:(\d+)\b/', $stderr, $m) === 1) {
            return sprintf('parse error near line %d (validate the Caddyfile locally to see details)', (int) $m[1]);
        }

        return 'the Caddyfile could not be parsed (validate it locally to see details)';
    }
}
