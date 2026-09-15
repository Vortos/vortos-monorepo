<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\ComposeServiceSet;

/**
 * Every env file a compose service reads must be accounted for by the pipeline, and only its declared
 * audience may read it (RC-4).
 *
 * WHY. The backup sidecar's bucket token and backup key lived in hand-made box files that nothing
 * declared, so nothing could say whether a new `env_file:` line on the app colour had just handed them
 * to the internet-facing container — or whether a rebuilt box would have them at all. The declaration
 * (config/pipeline.php) is now the single record of who receives which secret, and this rule holds the
 * topology to it:
 *
 *  - an app-wide runtime env file ({@see PipelineDefinition::$runtimeEnvFiles}) may be read by any service;
 *  - a {@see \Vortos\Pipeline\Model\SealedServiceEnv} or {@see \Vortos\Pipeline\Model\RootOfTrustEnvFile}
 *    only by its named services, all of which must actually mount it, as a required file, listed after
 *    every runtime env file (compose applies env files in order, so a later app-wide file would silently
 *    replace the scoped credential — isolation lost with no symptom);
 *  - anything else, or anything that cannot be resolved without the host, is refused.
 */
final class EnvFileScopeRule implements TopologyRuleInterface
{
    public const NAME = 'env_file_scope';

    /** @var array<string, ComposeServiceSet|null> absolute host path => allowed services; null = tooling-only, no service */
    private readonly array $scoped;

    /** @var array<string, true> */
    private readonly array $runtime;

    /**
     * @param list<string>                          $runtimeEnvFiles absolute host paths any service may read
     * @param array<string, ComposeServiceSet|null> $scopedFiles     bare file name in $deployDir => allowed services,
     *                                                                or null for a tooling-only file no service may mount
     */
    public function __construct(string $deployDir, array $runtimeEnvFiles, array $scopedFiles)
    {
        $runtime = [];
        foreach ($runtimeEnvFiles as $file) {
            $resolved = str_starts_with($file, '/') ? HostPath::resolve($file, '/') : null;
            if ($resolved === null) {
                throw new \InvalidArgumentException(sprintf('Runtime env file "%s" is not a resolvable absolute path.', $file));
            }
            $runtime[$resolved] = true;
        }

        $scoped = [];
        foreach ($scopedFiles as $name => $services) {
            $resolved = HostPath::resolve((string) $name, $deployDir);
            if ($resolved === null || isset($runtime[$resolved])) {
                throw new \InvalidArgumentException(sprintf('Scoped env file "%s" must be a distinct file beside the topology.', $name));
            }
            $scoped[$resolved] = $services;
        }

        $this->runtime = $runtime;
        $this->scoped = $scoped;
    }

    public static function forDefinition(PipelineDefinition $definition): self
    {
        $scoped = [];
        foreach ($definition->sealedServiceEnvs as $sealed) {
            $scoped[$sealed->target->value] = $sealed->services;
        }
        foreach ($definition->rootOfTrustEnvFiles as $root) {
            $scoped[$root->target->value] = $root->services;
        }
        foreach ($definition->sealedToolingEnvs as $tooling) {
            $scoped[$tooling->target->value] = null;
        }

        return new self($definition->remoteDeployDir, $definition->runtimeEnvFiles, $scoped);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(ComposeTopology $topology): array
    {
        $violations = [];
        /** @var array<string, list<string>> $consumers */
        $consumers = [];

        foreach ($topology->services as $service => $definition) {
            if (!\array_key_exists('env_file', $definition)) {
                continue;
            }

            $entries = $this->entries($definition['env_file']);
            if ($entries === null) {
                $violations[] = $this->violation(
                    EnvFileScopeViolationKind::Unverifiable,
                    $service,
                    'env_file',
                    'env_file must be a path, a list of paths, or a list of {path, required} mappings',
                );
                continue;
            }

            $lastRuntimePosition = -1;
            /** @var array<string, array{int, string}> $scopedPositions */
            $scopedPositions = [];

            foreach ($entries as $position => [$raw, $required]) {
                $path = $topology->resolveHostPath($raw);

                if ($path === null) {
                    $violations[] = $this->violation(
                        EnvFileScopeViolationKind::Unverifiable,
                        $service,
                        $raw,
                        'the path uses interpolation or traversal, so which file the service receives cannot be proven before it reaches the host',
                    );
                    continue;
                }

                if (isset($this->runtime[$path])) {
                    $lastRuntimePosition = $position;
                    continue;
                }

                if (\array_key_exists($path, $this->scoped) && $this->scoped[$path] === null) {
                    $violations[] = $this->violation(
                        EnvFileScopeViolationKind::ToolingOnly,
                        $service,
                        $raw,
                        'this file carries a deploy-tooling credential (sealedToolingEnvs) that only the deploy one-shots read; a running service must never hold it',
                    );
                    continue;
                }

                $allowed = $this->scoped[$path] ?? null;
                if ($allowed === null) {
                    $violations[] = $this->violation(
                        EnvFileScopeViolationKind::Undeclared,
                        $service,
                        $raw,
                        'no runtime env file, sealed service env or root-of-trust file in config/pipeline.php delivers this, so it exists only as a hand-made host file; declare it (sealedServiceEnvs) or remove it',
                    );
                    continue;
                }

                if (!$allowed->contains($service)) {
                    $violations[] = $this->violation(
                        EnvFileScopeViolationKind::ServiceNotAllowed,
                        $service,
                        $raw,
                        sprintf('this file is declared for [%s] only', implode(', ', $allowed->names)),
                    );
                    continue;
                }

                $consumers[$path][] = $service;
                $scopedPositions[$path] = [$position, $raw];

                if (!$required) {
                    $violations[] = $this->violation(
                        EnvFileScopeViolationKind::Optional,
                        $service,
                        $raw,
                        'required: false would boot the service without its credential if the file were missing; a declared secret is required',
                    );
                }
            }

            foreach ($scopedPositions as [$position, $raw]) {
                if ($lastRuntimePosition > $position) {
                    $violations[] = $this->violation(
                        EnvFileScopeViolationKind::ShadowedByRuntimeEnv,
                        $service,
                        $raw,
                        'an app-wide runtime env file is listed after it, and compose lets the later file win; list scoped files last',
                    );
                }
            }
        }

        foreach ($this->scoped as $path => $allowed) {
            // A tooling-only file has no service audience to be unconsumed by; the deploy one-shots read it.
            if ($allowed === null) {
                continue;
            }
            foreach ($allowed->names as $service) {
                if (!\in_array($service, $consumers[$path] ?? [], true)) {
                    $violations[] = $this->violation(
                        EnvFileScopeViolationKind::Unconsumed,
                        $service,
                        $path,
                        'declared as allowed to receive this file but does not mount it; narrow the declaration or remove the dead secret',
                    );
                }
            }
        }

        return $violations;
    }

    private function violation(EnvFileScopeViolationKind $kind, string $service, string $subject, string $detail): TopologyViolation
    {
        return new TopologyViolation(self::NAME, $kind, $service, $subject, $detail);
    }

    /** @return list<array{string, bool}>|null */
    private function entries(mixed $envFile): ?array
    {
        if (\is_string($envFile)) {
            return [[$envFile, true]];
        }

        if (!\is_array($envFile) || !array_is_list($envFile)) {
            return null;
        }

        $entries = [];
        foreach ($envFile as $entry) {
            if (\is_string($entry)) {
                $entries[] = [$entry, true];
                continue;
            }

            if (\is_array($entry) && \is_string($entry['path'] ?? null)) {
                $required = $entry['required'] ?? true;
                if (!\is_bool($required)) {
                    return null;
                }
                $entries[] = [$entry['path'], $required];
                continue;
            }

            return null;
        }

        return $entries;
    }
}
