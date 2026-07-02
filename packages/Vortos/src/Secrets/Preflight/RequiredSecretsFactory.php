<?php

declare(strict_types=1);

namespace Vortos\Secrets\Preflight;

/**
 * Builds {@see RequiredSecrets} from the application's `config/secrets.php`.
 *
 * The secrets preflight / deploy:doctor secret gate is only meaningful once the app declares which
 * secrets it needs, but the framework shipped no declaration surface — the only option was to
 * override the service definition (upstream P2-3). `config/secrets.php` returns the declared
 * references (or a closure returning them):
 *
 *     // config/secrets.php
 *     return [
 *         new SecretReference(new SecretKey('DATABASE_URL'), required: true, description: 'Primary DB DSN'),
 *         new SecretReference(new SecretKey('SENTRY_DSN'), required: false),
 *     ];
 */
final class RequiredSecretsFactory
{
    public function __invoke(string $projectDir): RequiredSecrets
    {
        $path = rtrim($projectDir, '/') . '/config/secrets.php';
        if ($projectDir === '' || !is_file($path)) {
            return new RequiredSecrets([]);
        }

        /** @var mixed $config */
        $config = require $path;

        if ($config instanceof \Closure) {
            $config = $config();
        }

        if ($config instanceof RequiredSecrets) {
            return $config;
        }

        if (!is_array($config)) {
            throw new \LogicException(sprintf(
                'config/secrets.php must return a list<%s>, a %s, or a Closure returning one; got %s.',
                SecretReference::class,
                RequiredSecrets::class,
                get_debug_type($config),
            ));
        }

        $references = [];
        foreach ($config as $reference) {
            if (!$reference instanceof SecretReference) {
                throw new \LogicException(sprintf(
                    'config/secrets.php must contain only %s instances; got %s.',
                    SecretReference::class,
                    get_debug_type($reference),
                ));
            }
            $references[] = $reference;
        }

        return new RequiredSecrets($references);
    }
}
