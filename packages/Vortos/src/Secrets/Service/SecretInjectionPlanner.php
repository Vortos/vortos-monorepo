<?php

declare(strict_types=1);

namespace Vortos\Secrets\Service;

use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Provider\SecretsProviderInterface;

/**
 * Pure: {@see RequiredSecrets} + a provider → a {@see SecretInjectionPlan}.
 * Produces the plan only — never writes anywhere, never reveals. A required
 * reference whose secret is missing fails closed here too (defense in depth even
 * if {@see SecretsPreflight} was skipped); an optional reference that is missing
 * is silently omitted from the plan.
 */
final class SecretInjectionPlanner
{
    public function plan(SecretsProviderInterface $provider, RequiredSecrets $required): SecretInjectionPlan
    {
        $entries = [];

        foreach ($required->references as $reference) {
            try {
                $value = $provider->get($reference->key);
            } catch (SecretNotFoundException $e) {
                if ($reference->required) {
                    throw $e;
                }

                continue;
            }

            $entries[] = new SecretInjectionEntry($reference->key->toEnvVar(), $value);
        }

        return new SecretInjectionPlan($entries);
    }
}
