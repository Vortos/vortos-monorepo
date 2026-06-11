<?php

declare(strict_types=1);

namespace Vortos\Iac\Export;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Terraform\TfVariable;

/**
 * Compile-time translation of Symfony '%env(...)%' placeholders (produced
 * from Env references by MessagingConfigCompilerPass and friends) into
 * static Terraform variable specs.
 *
 * Runs inside the compiler pass, where parameters still hold their RAW
 * placeholder strings — env values are never resolved, so they can never
 * leak into the compiled export spec or a generated file. The variable
 * carries the env var's declared default (read from the
 * vortos.env_default.* parameter) and a sensitive flag derived from the
 * name; the value comes from terraform.tfvars at apply time.
 */
final class PlaceholderTranslator
{
    private const ENV_PLACEHOLDER = '/^%env\(([^()]+)\)%$/';

    /**
     * Scalar passthrough for literals; '%env(...)%' strings become
     * SpecValue::VAR arrays. Composite strings embedding a placeholder are
     * rejected — they cannot be represented as a typed Terraform variable.
     */
    public static function translate(mixed $value, ContainerBuilder $container, string $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (preg_match(self::ENV_PLACEHOLDER, $value, $m)) {
            return self::toVariableSpec($m[1], $container, $context);
        }

        if (str_contains($value, '%env(')) {
            throw new \LogicException(sprintf(
                "%s: composite value '%s' embeds an env placeholder — declare the whole value as one Env reference instead.",
                $context,
                $value,
            ));
        }

        return $value;
    }

    /** @return array{__var: array{name: string, type: string, default: mixed, description: string, sensitive: bool}} */
    private static function toVariableSpec(string $expression, ContainerBuilder $container, string $context): array
    {
        $parts = explode(':', $expression);
        $envName = array_pop($parts);
        $type = 'string';
        $default = null;

        while ($parts !== []) {
            $processor = array_shift($parts);

            switch ($processor) {
                case 'int':
                case 'float':
                    $type = 'number';
                    break;
                case 'bool':
                    $type = 'bool';
                    break;
                case 'string':
                    break;
                case 'default':
                    $parameterName = array_shift($parts);
                    if ($parameterName !== null && $container->hasParameter($parameterName)) {
                        $default = $container->getParameter($parameterName);
                    }
                    break;
                default:
                    throw new \LogicException(sprintf(
                        "%s: env placeholder '%%env(%s)%%' uses processor '%s', which has no Terraform equivalent.",
                        $context,
                        $expression,
                        $processor,
                    ));
            }
        }

        return [SpecValue::VAR => [
            'name' => strtolower($envName),
            'type' => $type,
            'default' => $default,
            'description' => sprintf('From environment variable %s.', $envName),
            'sensitive' => (bool) preg_match(TfVariable::SECRET_NAME_PATTERN, $envName),
        ]];
    }
}
