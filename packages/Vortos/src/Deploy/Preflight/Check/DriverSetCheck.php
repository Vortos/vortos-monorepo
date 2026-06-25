<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployTargetRegistry;

/**
 * Fail-closed: every driver the definition selects (host / registry / credential /
 * strategy) must actually be registered. A selection naming an uninstalled or
 * misspelled driver is refused with the unknown key *and* the set of registered keys
 * — so the operator sees exactly what is available, never a 3am "unknown driver".
 */
final class DriverSetCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly DeployTargetRegistry $targets,
        private readonly ContainerRegistryRegistry $registries,
        private readonly CredentialProviderRegistry $credentials,
        private readonly DeployStrategyRegistry $strategies,
    ) {}

    public function id(): string
    {
        return 'driver_set.registered';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::DriverSet;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $def = $context->definition;
        $missing = [];

        if (!$this->targets->has($def->host)) {
            $missing[] = sprintf('host "%s" (registered: [%s])', $def->host, implode(', ', $this->targets->keys()));
        }
        if (!$this->registries->has($def->registry)) {
            $missing[] = sprintf('registry "%s" (registered: [%s])', $def->registry, implode(', ', $this->registries->keys()));
        }
        if (!$this->credentials->has($def->credential)) {
            $missing[] = sprintf('credential "%s" (registered: [%s])', $def->credential, implode(', ', $this->credentials->keys()));
        }
        if (!$this->strategies->has($def->strategy)) {
            $missing[] = sprintf('strategy "%s" (registered: [%s])', $def->strategy->value, implode(', ', $this->strategies->keys()));
        }

        if ($missing !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'one or more selected drivers are not registered',
                implode('; ', $missing),
                'Install the missing driver package or correct the selection in config/deploy.php.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'all selected drivers are registered',
            sprintf(
                'host=%s registry=%s credential=%s strategy=%s',
                $def->host,
                $def->registry,
                $def->credential,
                $def->strategy->value,
            ),
        );
    }
}
