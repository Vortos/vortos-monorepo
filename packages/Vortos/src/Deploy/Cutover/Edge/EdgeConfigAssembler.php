<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;

/**
 * The precedence collaborator: turns a {@see DesiredRoute} into the exact edge config to load.
 *
 *  - base config present -> adapt the operator's base config, merge the live color structurally, run
 *    it through the config firewall, and return the validated result (adapt-merge path).
 *  - base config absent   -> the from-scratch {@see EdgeConfigGenerator} (unchanged, backward
 *    compatible).
 *
 * Both the cutover edge router and the preflight doctor gate go through this ONE method, so what
 * preflight validates is byte-for-byte what cutover loads. It depends on the {@see
 * EdgeConfigAdapterInterface} port, never a concrete proxy driver.
 */
final class EdgeConfigAssembler
{
    public function __construct(
        private readonly EdgeBaseConfigResolver $resolver,
        private readonly EdgeConfigAdapterInterface $adapter,
        private readonly EdgeConfigMerger $merger,
        private readonly ConfigInvariantValidator $validator,
        private readonly EdgeConfigGenerator $generator,
        private readonly string $adminListen = 'localhost:2019',
        private readonly ?string $baseConfigPath = null,
    ) {}

    /** True when an operator base config is configured and resolvable (adapt-merge path is active). */
    public function hasBaseConfig(): bool
    {
        return $this->resolver->resolve($this->baseConfigPath) !== null;
    }

    public function assembleForRoute(DesiredRoute $desired): AssembledEdgeConfig
    {
        $base = $this->resolver->resolve($this->baseConfigPath);

        if ($base === null) {
            return new AssembledEdgeConfig(
                config: $this->generator->generateForRoute($desired, $this->adminListen),
                usedBaseConfig: false,
            );
        }

        $adapted = $this->adapter->adapt($base);
        $merged = $this->merger->merge($adapted, $desired);
        $validated = $this->validator->validate($adapted, $merged, $desired->domain);

        return new AssembledEdgeConfig(
            config: $validated,
            usedBaseConfig: true,
            baseConfigSha256: $base->sha256,
            mergeOutcome: $merged,
        );
    }
}
