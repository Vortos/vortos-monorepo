<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Injects a container parameter or environment variable into a constructor parameter or property.
 *
 * Resolved at container compile time — zero runtime overhead.
 * No manual wiring in services.php is required.
 *
 * ## Container parameter
 *
 *   public function __construct(
 *       #[Value('vortos_object_store.bucket.temporary_key_prefix')]
 *       string $temporaryKeyPrefix,
 *   ) {}
 *
 * ## Environment variable
 *
 *   public function __construct(
 *       #[Value(env: 'STRIPE_SECRET_KEY')]
 *       string $stripeKey,
 *   ) {}
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class Value extends Autowire
{
    /**
     * @param string|null $param Container parameter name (e.g. 'vortos_object_store.bucket.key_prefix')
     * @param string|null $env   Environment variable name (e.g. 'APP_SECRET')
     *
     * @throws \LogicException When neither or both of $param and $env are provided
     */
    public function __construct(?string $param = null, ?string $env = null)
    {
        if ($param === null && $env === null) {
            throw new \LogicException(
                sprintf('#[%s] requires either $param or $env.', self::class),
            );
        }

        if ($param !== null && $env !== null) {
            throw new \LogicException(
                sprintf('#[%s] must declare exactly one of $param or $env, not both.', self::class),
            );
        }

        parent::__construct(param: $param, env: $env);
    }
}
