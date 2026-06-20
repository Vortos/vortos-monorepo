<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Injects a container parameter, environment variable, or Expression Language expression
 * into a constructor parameter or property.
 *
 * Resolved at container compile time — zero runtime overhead.
 * No manual wiring in services.php is required.
 *
 * ## Plain container parameter
 *
 *   #[Value('my.param')]                      // injects %my.param%
 *
 * ## Parameter expression (already contains %)
 *
 *   #[Value('%prefix%role_permissions')]       // injected as-is
 *
 * ## Environment variable
 *
 *   #[Value(env: 'STRIPE_SECRET_KEY')]
 *
 * ## Expression Language (requires symfony/expression-language)
 *
 *   #[Value(expr: 'parameter("a") ~ "_" ~ parameter("b")')]
 *
 * ## Parameter with fallback default (requires symfony/expression-language)
 *
 *   #[Value('my.param', default: 'fallback')]
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class Value extends Autowire
{
    /**
     * @param string|null $param   Container parameter name OR a %-delimited expression like '%prefix%suffix'
     * @param string|null $env     Environment variable name
     * @param string|null $expr    Symfony Expression Language string (requires symfony/expression-language)
     * @param mixed       $default Fallback when $param is absent (requires $param + symfony/expression-language)
     *
     * @throws \LogicException When validation rules are violated
     */
    public function __construct(
        ?string $param   = null,
        ?string $env     = null,
        ?string $expr    = null,
        mixed   $default = null,
    ) {
        $given = ($param !== null ? 1 : 0) + ($env !== null ? 1 : 0) + ($expr !== null ? 1 : 0);

        if ($given === 0) {
            throw new \LogicException(
                sprintf('#[%s] requires exactly one of $param, $env, or $expr.', self::class),
            );
        }

        if ($given > 1) {
            throw new \LogicException(
                sprintf('#[%s] must declare exactly one of $param, $env, or $expr, not multiple.', self::class),
            );
        }

        if ($default !== null && $env !== null) {
            throw new \LogicException(
                sprintf('#[%s] $default is only valid alongside $param, not $env.', self::class),
            );
        }

        if ($default !== null && $expr !== null) {
            throw new \LogicException(
                sprintf('#[%s] $default is only valid alongside $param, not $expr.', self::class),
            );
        }

        if ($default !== null && !class_exists(\Symfony\Component\ExpressionLanguage\Expression::class)) {
            throw new \LogicException(
                sprintf(
                    '#[%s] with $default requires symfony/expression-language. '
                    . 'Install it with: composer require symfony/expression-language',
                    self::class,
                ),
            );
        }

        match (true) {
            $expr !== null    => parent::__construct(expression: $expr),
            $env !== null     => parent::__construct(env: $env),
            $default !== null => parent::__construct(expression: sprintf(
                'container.hasParameter("%s") ? parameter("%s") : %s',
                $param,
                $param,
                json_encode($default),
            )),
            str_contains($param, '%') => parent::__construct(value: $param),
            default => parent::__construct(param: $param),
        };
    }
}
