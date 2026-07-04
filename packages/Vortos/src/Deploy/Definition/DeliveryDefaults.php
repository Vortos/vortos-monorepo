<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

/**
 * Default coordinates for config/secret delivery to the deploy target (G3). Lives in the Definition
 * namespace so the config-tree names are declared in one auditable place.
 */
final readonly class DeliveryDefaults
{
    /** Config trees (relative to the project dir) mounted into the containers and shipped as-is. */
    public const CONFIG_TREES = ['docker', 'observability'];

    public const ENV_FILE = '.env.prod';
    public const COMPOSE_FILE = 'docker-compose.prod.yaml';
    public const SECRETS_FILE = 'vortos-secrets.age';
}
