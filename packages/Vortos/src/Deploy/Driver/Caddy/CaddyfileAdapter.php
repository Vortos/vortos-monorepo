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

        $argv = [
            'docker', 'run', '--rm', '-i', '--network', 'none',
            $this->adaptImage,
            'caddy', 'adapt', '--adapter', 'caddyfile',
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
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw EdgeBaseConfigException::invalidJson($path, $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw EdgeBaseConfigException::invalidJson($path, 'top-level value is not an object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
