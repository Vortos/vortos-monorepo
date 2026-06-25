<?php

declare(strict_types=1);

namespace Vortos\Secrets\Service;

use Vortos\Secrets\Preflight\PreflightReport;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Value\SecretKey;

/**
 * Diffs {@see RequiredSecrets} against what a provider actually has → a
 * {@see PreflightReport}. Uses `provider->list()` (names only) rather than
 * `provider->get()` — checking presence never needs to reveal a value. Reused by
 * `secrets:preflight` (CI/doctor gate) and, later, `deploy:doctor`.
 */
final class SecretsPreflight
{
    public function check(SecretsProviderInterface $provider, RequiredSecrets $required): PreflightReport
    {
        $availableNames = array_map(
            static fn (SecretKey $key): string => $key->value(),
            $provider->list(),
        );

        $present = [];
        $missing = [];

        foreach ($required->requiredKeys() as $key) {
            if (in_array($key->value(), $availableNames, true)) {
                $present[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        return new PreflightReport($present, $missing);
    }
}
