<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\BindAccess;
use Vortos\Pipeline\Model\ComposeServiceSet;

/**
 * Every host path a compose service bind-mounts must be accounted for by the pipeline, and only its
 * declared audience may mount it, no wider than declared (RC-3).
 *
 * WHY. The deploy synced the compose file and nothing it pointed at. A relative bind resolved against
 * a host copy frozen since July — the Postgres init directory that grants replication access — and the
 * host root reached a third-party log agent together with every plaintext secret beside the topology.
 * Neither was visible anywhere: a bind mount is one line, and nothing asked where its source came from.
 * The declarations in config/pipeline.php are now the record, and this rule holds the topology to them:
 *
 *  - a source inside the deploy dir must be a {@see \Vortos\Pipeline\Model\SyncedHostPath} (or lie inside
 *    a synced directory), mounted read-only — the deploy delivers it, so a writable mount is drift;
 *  - any other absolute source must be a {@see \Vortos\Pipeline\Model\HostSystemBind}, matched exactly
 *    (a declared `/var/lib/docker/containers` does not authorise `/var/lib/docker`), no wider than its
 *    declared access;
 *  - a bind of the deploy dir or an ancestor of it must be shadowed by a tmpfs over the deploy dir
 *    inside the container, or every secret on the host is readable by that service;
 *  - named volumes are docker-managed and out of scope — except a local volume whose driver options
 *    bind a host device, which is a bind mount under another name and is judged as one;
 *  - anything that cannot be resolved without the host is refused.
 */
final class BindMountScopeRule implements TopologyRuleInterface
{
    public const NAME = 'bind_mount_scope';

    /** @var array<string, ComposeServiceSet> absolute host path => allowed services */
    private readonly array $synced;

    /** @var array<string, array{ComposeServiceSet, BindAccess}> absolute host path => [allowed services, access] */
    private readonly array $system;

    private readonly string $deployDir;

    /**
     * @param array<string, ComposeServiceSet>                      $syncedPaths project-relative path => allowed services
     * @param array<string, array{ComposeServiceSet, BindAccess}>   $systemBinds absolute host path => [allowed services, access]
     */
    public function __construct(string $deployDir, array $syncedPaths, array $systemBinds)
    {
        $resolvedDeployDir = str_starts_with($deployDir, '/') ? HostPath::resolve($deployDir, '/') : null;
        if ($resolvedDeployDir === null) {
            throw new \InvalidArgumentException(sprintf('Deploy dir "%s" is not a resolvable absolute path.', $deployDir));
        }

        $synced = [];
        foreach ($syncedPaths as $path => $services) {
            $resolved = HostPath::resolve((string) $path, $resolvedDeployDir);
            if ($resolved === null || str_starts_with((string) $path, '/') || $resolved === $resolvedDeployDir) {
                throw new \InvalidArgumentException(sprintf('Synced path "%s" must be a path inside the deploy dir.', $path));
            }
            $synced[$resolved] = $services;
        }

        $system = [];
        foreach ($systemBinds as $path => $declaration) {
            $resolved = str_starts_with((string) $path, '/') ? HostPath::resolve((string) $path, '/') : null;
            if ($resolved === null || isset($synced[$resolved]) || self::within($resolved, $resolvedDeployDir)) {
                throw new \InvalidArgumentException(sprintf(
                    'Host system bind "%s" must be an absolute path outside the deploy dir; files the deploy delivers are synced paths.',
                    $path,
                ));
            }
            $system[$resolved] = $declaration;
        }

        $this->deployDir = $resolvedDeployDir;
        $this->synced = $synced;
        $this->system = $system;
    }

    public static function forDefinition(PipelineDefinition $definition): self
    {
        $synced = [];
        foreach ($definition->syncedHostPaths as $path) {
            $synced[$path->path->value] = $path->services;
        }

        $system = [];
        foreach ($definition->hostSystemBinds as $bind) {
            $system[$bind->path] = [$bind->services, $bind->access];
        }

        return new self($definition->remoteDeployDir, $synced, $system);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(ComposeTopology $topology): array
    {
        $violations = [];
        /** @var array<string, list<string>> $consumers declared absolute path => services that mount it */
        $consumers = [];

        foreach ($topology->services as $service => $definition) {
            $mounts = [];
            $shadows = $this->tmpfsTargets($definition['tmpfs'] ?? null);

            if (\array_key_exists('volumes', $definition)) {
                if (!\is_array($definition['volumes']) || !array_is_list($definition['volumes'])) {
                    $violations[] = $this->violation(BindMountScopeViolationKind::Unverifiable, $service, 'volumes', 'volumes must be a list of mounts');
                    continue;
                }

                foreach ($definition['volumes'] as $entry) {
                    $mount = $this->mount($entry, $topology);
                    if ($mount === null) {
                        continue;
                    }
                    if ($mount['kind'] === 'tmpfs') {
                        $shadows[] = $mount['target'];
                        continue;
                    }
                    $mounts[] = $mount;
                }
            }

            foreach ($mounts as $mount) {
                if ($mount['kind'] === 'unverifiable') {
                    $violations[] = $this->violation(BindMountScopeViolationKind::Unverifiable, $service, $mount['subject'], $mount['detail']);
                    continue;
                }

                $source = $mount['source'];
                $declared = $this->declaredFor($source);

                if ($declared === null) {
                    $violations[] = $this->violation(
                        BindMountScopeViolationKind::Undeclared,
                        $service,
                        $mount['subject'],
                        self::within($source, $this->deployDir)
                            ? 'this path beside the topology is not a synced path in config/pipeline.php, so the host copy is whatever someone last left there; declare it (syncedHostPaths) or remove the mount'
                            : 'this host path is not declared in config/pipeline.php (hostSystemBinds); declare its audience, access and reason, or remove the mount',
                    );
                } else {
                    [$key, $allowed, $access] = $declared;

                    if (!$allowed->contains($service)) {
                        $violations[] = $this->violation(
                            BindMountScopeViolationKind::ServiceNotAllowed,
                            $service,
                            $mount['subject'],
                            sprintf('this path is declared for [%s] only', implode(', ', $allowed->names)),
                        );
                    } else {
                        $consumers[$key][] = $service;

                        if ($mount['access']->isWiderThan($access)) {
                            $violations[] = $this->violation(
                                BindMountScopeViolationKind::AccessWiderThanDeclared,
                                $service,
                                $mount['subject'],
                                isset($this->synced[$key])
                                    ? 'a synced path is desired state from git and must be mounted read-only (:ro), or the container can turn it back into drift'
                                    : 'mounted read-write but declared read-only; add :ro or widen the declaration deliberately',
                            );
                        }
                    }
                }

                if ($this->exposesDeployDir($source, $mount['target'], $shadows)) {
                    $violations[] = $this->violation(
                        BindMountScopeViolationKind::DeployDirExposed,
                        $service,
                        $mount['subject'],
                        sprintf(
                            'this mount contains %s, where every host secret lives; add a tmpfs over %s in this service',
                            $this->deployDir,
                            self::containerPathOf($this->deployDir, $source, $mount['target']),
                        ),
                    );
                }
            }
        }

        foreach ([...array_keys($this->synced), ...array_keys($this->system)] as $path) {
            $allowed = $this->synced[$path] ?? $this->system[$path][0];
            foreach ($allowed->names as $service) {
                if (!\in_array($service, $consumers[$path] ?? [], true)) {
                    $violations[] = $this->violation(
                        BindMountScopeViolationKind::Unconsumed,
                        $service,
                        $path,
                        'declared as allowed to mount this path but does not; narrow the declaration or remove it',
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * The declaration covering an absolute source: an exact synced path, a path inside a synced
     * directory, or an exact host system bind.
     *
     * @return array{string, ComposeServiceSet, BindAccess}|null [declared key, allowed services, maximum access]
     */
    private function declaredFor(string $source): ?array
    {
        foreach ($this->synced as $path => $services) {
            if (self::within($source, $path)) {
                return [$path, $services, BindAccess::ReadOnly];
            }
        }

        if (isset($this->system[$source])) {
            return [$source, $this->system[$source][0], $this->system[$source][1]];
        }

        return null;
    }

    /** @param list<string> $shadows container paths covered by a tmpfs in the same service */
    private function exposesDeployDir(string $source, string $target, array $shadows): bool
    {
        if (!self::within($this->deployDir, $source)) {
            return false;
        }

        $exposed = self::containerPathOf($this->deployDir, $source, $target);
        foreach ($shadows as $shadow) {
            if (self::within($exposed, $shadow)) {
                return false;
            }
        }

        return true;
    }

    /** Where host path $hostPath appears inside a container that mounts $source at $target. */
    private static function containerPathOf(string $hostPath, string $source, string $target): string
    {
        $relative = ltrim(substr($hostPath, \strlen(rtrim($source, '/'))), '/');

        return rtrim($target, '/') . ($relative === '' ? '' : '/' . $relative);
    }

    private static function within(string $path, string $ancestor): bool
    {
        return $ancestor === '/' || $path === $ancestor || str_starts_with($path, $ancestor . '/');
    }

    /**
     * One entry of a service's `volumes:` list, classified.
     *
     * @return array{kind: 'bind', source: string, target: string, access: BindAccess, subject: string}|array{kind: 'tmpfs', target: string}|array{kind: 'unverifiable', subject: string, detail: string}|null
     *         null for docker-managed named or anonymous volumes
     */
    private function mount(mixed $entry, ComposeTopology $topology): ?array
    {
        if (\is_string($entry)) {
            return $this->shortSyntax($entry, $topology);
        }

        if (!\is_array($entry)) {
            return ['kind' => 'unverifiable', 'subject' => get_debug_type($entry), 'detail' => 'a volume entry must be a string or a mapping'];
        }

        $type = $entry['type'] ?? null;
        $source = $entry['source'] ?? null;
        $target = $entry['target'] ?? null;
        $subject = sprintf('%s:%s', \is_string($source) ? $source : '', \is_string($target) ? $target : '');

        if (!\is_string($type) || !\is_string($target) || !str_starts_with($target, '/')) {
            return ['kind' => 'unverifiable', 'subject' => $subject, 'detail' => 'a long-syntax mount needs a type and an absolute target'];
        }

        $readOnly = $entry['read_only'] ?? false;
        if (!\is_bool($readOnly)) {
            return ['kind' => 'unverifiable', 'subject' => $subject, 'detail' => 'read_only must be a boolean'];
        }
        $access = $readOnly ? BindAccess::ReadOnly : BindAccess::ReadWrite;

        return match ($type) {
            'tmpfs' => ['kind' => 'tmpfs', 'target' => rtrim($target, '/') ?: '/'],
            'bind' => \is_string($source)
                ? $this->bind($source, $target, $access, $subject, $topology)
                : ['kind' => 'unverifiable', 'subject' => $subject, 'detail' => 'a bind mount needs a source'],
            'volume' => \is_string($source) ? $this->namedVolume($source, $target, $access, $subject, $topology) : null,
            default => ['kind' => 'unverifiable', 'subject' => $subject, 'detail' => sprintf('mount type "%s" is not one this policy can prove safe', $type)],
        };
    }

    /**
     * @return array{kind: 'bind', source: string, target: string, access: BindAccess, subject: string}|array{kind: 'unverifiable', subject: string, detail: string}|null
     */
    private function shortSyntax(string $entry, ComposeTopology $topology): ?array
    {
        $parts = explode(':', $entry);
        if (\count($parts) === 1) {
            return null; // anonymous volume
        }
        if (\count($parts) > 3) {
            return ['kind' => 'unverifiable', 'subject' => $entry, 'detail' => 'expected SOURCE:TARGET[:MODE]'];
        }

        [$source, $target] = $parts;
        $options = explode(',', $parts[2] ?? '');
        $access = \in_array('ro', $options, true) ? BindAccess::ReadOnly : BindAccess::ReadWrite;

        if (!str_starts_with($target, '/')) {
            return ['kind' => 'unverifiable', 'subject' => $entry, 'detail' => 'the container target must be absolute'];
        }

        if ($source === '' || str_starts_with($source, '/') || str_starts_with($source, '.') || str_starts_with($source, '~') || str_contains($source, '$')) {
            return $this->bind($source, $target, $access, $entry, $topology);
        }

        return $this->namedVolume($source, $target, $access, $entry, $topology);
    }

    /**
     * @return array{kind: 'bind', source: string, target: string, access: BindAccess, subject: string}|array{kind: 'unverifiable', subject: string, detail: string}
     */
    private function bind(string $source, string $target, BindAccess $access, string $subject, ComposeTopology $topology): array
    {
        $resolved = $topology->resolveHostPath($source);
        if ($resolved === null || str_starts_with($source, '~')) {
            return [
                'kind' => 'unverifiable',
                'subject' => $subject,
                'detail' => 'the source uses interpolation, a home directory or traversal, so which host path the service receives cannot be proven before it reaches the host',
            ];
        }

        return ['kind' => 'bind', 'source' => $resolved, 'target' => rtrim($target, '/') ?: '/', 'access' => $access, 'subject' => $subject];
    }

    /**
     * A named volume is docker-managed and out of scope — unless its driver options bind a host device,
     * which is a bind mount under another name.
     *
     * @return array{kind: 'bind', source: string, target: string, access: BindAccess, subject: string}|array{kind: 'unverifiable', subject: string, detail: string}|null
     */
    private function namedVolume(string $name, string $target, BindAccess $access, string $subject, ComposeTopology $topology): ?array
    {
        $options = $topology->volumes[$name]['driver_opts'] ?? null;
        if (!\is_array($options)) {
            return null;
        }

        $o = $options['o'] ?? '';
        if (!\is_string($o) || !\in_array('bind', array_map('trim', explode(',', $o)), true)) {
            return null;
        }

        $device = $options['device'] ?? null;
        if (!\is_string($device)) {
            return ['kind' => 'unverifiable', 'subject' => $subject, 'detail' => sprintf('volume "%s" binds a host device it does not name', $name)];
        }

        return $this->bind($device, $target, $access, $subject, $topology);
    }

    /** @return list<string> container paths a service-level `tmpfs:` covers */
    private function tmpfsTargets(mixed $tmpfs): array
    {
        $entries = \is_string($tmpfs) ? [$tmpfs] : (\is_array($tmpfs) ? $tmpfs : []);
        $targets = [];
        foreach ($entries as $entry) {
            if (\is_string($entry) && str_starts_with($entry, '/')) {
                $targets[] = rtrim(explode(':', $entry)[0], '/') ?: '/';
            }
        }

        return $targets;
    }

    private function violation(BindMountScopeViolationKind $kind, string $service, string $subject, string $detail): TopologyViolation
    {
        return new TopologyViolation(self::NAME, $kind, $service, $subject, $detail);
    }
}
