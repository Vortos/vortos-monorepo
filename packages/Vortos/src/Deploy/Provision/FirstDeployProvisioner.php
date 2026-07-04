<?php

declare(strict_types=1);

namespace Vortos\Deploy\Provision;

/**
 * Builds the idempotent first-deploy provisioning plan (G4): everything a fresh box needs before the
 * app can boot — RS256 JWT signing keys, an up-to-date schema, and a satisfied secrets gate.
 *
 * Pure and ordered: key generation only when the keys are absent (idempotent — safe to run on every
 * deploy), then migrations (vortos:migrate applies pending only), then the fail-closed secrets
 * preflight. The command layer executes the plan; this class only decides what must run.
 */
final class FirstDeployProvisioner
{
    /** @return list<ProvisionStep> */
    public function plan(bool $jwtKeysPresent, string $keyOutputDir, string $environment): array
    {
        $steps = [];

        if (!$jwtKeysPresent) {
            $steps[] = new ProvisionStep(
                'vortos:auth:keys:generate',
                ['--out=' . $keyOutputDir],
                'Generate RS256 JWT signing keys (absent)',
            );
        }

        $steps[] = new ProvisionStep(
            'vortos:migrate',
            ['--force'],
            'Apply pending database migrations',
        );

        $steps[] = new ProvisionStep(
            'secrets:preflight',
            ['--env=' . $environment],
            'Verify every required runtime secret is present',
        );

        return $steps;
    }
}
