<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

use RuntimeException;

/**
 * Docker Engine API implementation, spoken over plain HTTP.
 *
 * WHY HTTP AND NOT THE `docker` CLI: the framework's deploy path shells out to `docker` with
 * `DOCKER_HOST` pointed at the least-privilege socket-proxy. That works there because the deploy
 * one-shot is built to carry the CLI. The backup sidecar is not — it is an application image plus the
 * PostgreSQL client, and adding a Docker CLI (and keeping it in step with the daemon) to run drills
 * would be a large amount of surface for a handful of REST calls. The Engine API is just JSON over
 * HTTP, so we speak it directly: no extra binary, no shell quoting, and the whole thing sits behind
 * {@see ContainerRuntimeInterface} for testing.
 *
 * SECURITY: `$endpoint` must point at the docker-socket-proxy (`tcp://docker-socket-proxy:2375`),
 * never a raw `/var/run/docker.sock`. Mounting the real socket into an application container is
 * equivalent to granting root on the host; the proxy's allowlist is what makes this acceptable, and
 * the constructor refuses a unix socket to keep that property from being quietly given away. The
 * endpoints used here — images/create, containers/create|start|delete, containers/json — are all
 * within the allowlist the deploy path already requires (CONTAINERS, IMAGES, NETWORKS, POST).
 */
final class DockerEngineContainerRuntime implements ContainerRuntimeInterface
{
    /**
     * Fallback only. The real version is negotiated from the daemon at first use — see
     * {@see apiVersion()}. Pinning a constant here is what broke the first production drill: the
     * hardcoded `v1.43` was rejected outright by a newer daemon ("client version 1.43 is too old,
     * minimum supported API version is 1.44"), and a pin can only ever rot in that direction as
     * hosts are upgraded.
     */
    private const FALLBACK_API_VERSION = 'v1.44';

    private ?string $negotiatedVersion = null;

    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeoutSeconds = 120,
    ) {
        if ($endpoint === '') {
            throw new RuntimeException('Docker endpoint must not be empty; expected tcp://docker-socket-proxy:2375.');
        }
        if (str_starts_with($endpoint, 'unix://') || str_contains($endpoint, '.sock')) {
            throw new RuntimeException(
                'Refusing a raw Docker socket: drills must reach Docker through the least-privilege '
                . 'socket-proxy (tcp://host:2375), never /var/run/docker.sock.',
            );
        }
    }

    public function ensureImage(string $image): void
    {
        // images/create is a no-op when the image is already present, so this is safe to call every
        // drill and keeps the first run on a fresh host from failing on a missing image.
        [$status, $body] = $this->request('POST', '/images/create?fromImage=' . rawurlencode($image));

        if ($status >= 400) {
            throw new RuntimeException(sprintf('Cannot pull drill image "%s" (HTTP %d): %s', $image, $status, $body));
        }
    }

    public function run(ContainerSpec $spec): ContainerHandle
    {
        $config = [
            'Image' => $spec->image,
            'Env' => array_map(
                static fn (string $k, string $v): string => $k . '=' . $v,
                array_keys($spec->env),
                array_values($spec->env),
            ),
            'Labels' => $spec->labels,
            'HostConfig' => [
                // Disposable by construction: never restart, and take the anonymous volumes with it.
                'RestartPolicy' => ['Name' => 'no'],
                'AutoRemove' => false, // we remove explicitly, so teardown failures stay visible
            ],
        ];

        if ($spec->tmpfsPath !== null) {
            $config['HostConfig']['Tmpfs'] = [
                $spec->tmpfsPath => 'rw,size=' . $spec->tmpfsSizeBytes,
            ];
        }

        if ($spec->network !== null) {
            $config['HostConfig']['NetworkMode'] = $spec->network;
        }

        [$status, $body] = $this->request(
            'POST',
            '/containers/create?name=' . rawurlencode($spec->name),
            $config,
        );

        if ($status >= 400) {
            throw new RuntimeException(sprintf('Cannot create drill container (HTTP %d): %s', $status, $body));
        }

        $decoded = json_decode($body, true);
        $id = is_array($decoded) && is_string($decoded['Id'] ?? null) ? $decoded['Id'] : null;
        if ($id === null) {
            throw new RuntimeException('Docker did not return a container id: ' . $body);
        }

        [$startStatus, $startBody] = $this->request('POST', '/containers/' . $id . '/start');
        if ($startStatus >= 400) {
            // Don't leak the container we just created because it failed to start.
            $this->remove(new ContainerHandle($id, $spec->name, $spec->name));

            throw new RuntimeException(sprintf('Cannot start drill container (HTTP %d): %s', $startStatus, $startBody));
        }

        return new ContainerHandle($id, $spec->name, $spec->name);
    }

    public function remove(ContainerHandle $handle): void
    {
        // force=1 stops it first; v=1 takes the anonymous volumes. A 404 means someone beat us to it,
        // which is success as far as teardown is concerned.
        $this->request('DELETE', '/containers/' . $handle->id . '?force=1&v=1');
    }

    public function removeOrphans(string $label, ?string $exceptId = null): int
    {
        $filters = rawurlencode(json_encode(['label' => [$label]], JSON_THROW_ON_ERROR));
        [$status, $body] = $this->request('GET', '/containers/json?all=1&filters=' . $filters);

        if ($status >= 400) {
            return 0; // sweeping is best-effort; never fail a drill because the sweep could not list
        }

        $containers = json_decode($body, true);
        if (!is_array($containers)) {
            return 0;
        }

        $removed = 0;
        foreach ($containers as $container) {
            if (!is_array($container) || !is_string($container['Id'] ?? null)) {
                continue;
            }
            if ($exceptId !== null && $container['Id'] === $exceptId) {
                continue;
            }

            $this->request('DELETE', '/containers/' . $container['Id'] . '?force=1&v=1');
            $removed++;
        }

        return $removed;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{0: int, 1: string} [status, body]
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        return $this->rawRequest($method, '/' . $this->apiVersion() . $path, $payload);
    }

    /**
     * Ask the daemon what version it speaks, once per instance.
     *
     * The Engine API rejects a client version older than its minimum outright, so a hardcoded
     * constant is a latent failure that triggers on someone else's schedule — whenever the host's
     * Docker is upgraded, which for a weekly drill means the breakage is discovered by the drill
     * failing rather than by anything watching. `/version` is inside the socket-proxy's existing
     * allowlist (VERSION=1), so this costs one cheap call and no new privilege.
     */
    private function apiVersion(): string
    {
        if ($this->negotiatedVersion !== null) {
            return $this->negotiatedVersion;
        }

        try {
            [$status, $body] = $this->rawRequest('GET', '/version');
            if ($status < 400) {
                $decoded = json_decode($body, true);
                $version = is_array($decoded) ? ($decoded['ApiVersion'] ?? null) : null;
                if (is_string($version) && preg_match('/^\d+\.\d+$/', $version) === 1) {
                    return $this->negotiatedVersion = 'v' . $version;
                }
            }
        } catch (\Throwable) {
            // Fall through to the pinned fallback — a failed negotiation must not be fatal on its
            // own; the subsequent real call will surface a far more useful error.
        }

        return $this->negotiatedVersion = self::FALLBACK_API_VERSION;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{0: int, 1: string} [status, body]
     */
    private function rawRequest(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim($this->httpEndpoint(), '/') . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialise HTTP client for the Docker Engine API.');
        }

        $headers = ['Accept: application/json'];
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5 — the handle is released
        // when $ch goes out of scope.

        if ($body === false) {
            throw new RuntimeException(sprintf('Docker Engine API request failed (%s %s): %s', $method, $path, $error));
        }

        return [$status, (string) $body];
    }

    /** Normalise `tcp://host:port` (the DOCKER_HOST convention) to an HTTP URL. */
    private function httpEndpoint(): string
    {
        if (str_starts_with($this->endpoint, 'tcp://')) {
            return 'http://' . substr($this->endpoint, 6);
        }

        return $this->endpoint;
    }
}
