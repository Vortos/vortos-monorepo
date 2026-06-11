<?php

declare(strict_types=1);

namespace Vortos\Iac\Export;

use Vortos\Iac\Terraform\TerraformDocument;
use Vortos\Iac\Terraform\TfReference;
use Vortos\Iac\Terraform\TfValueInterface;
use Vortos\Iac\Terraform\TfVariable;

/**
 * Encoding of values inside the compiled export spec
 * (the static 'vortos.iac.exports' parameter):
 *
 *   scalar                  → literal
 *   ['__var' => [...]]      → Terraform input variable (from an Env reference)
 *   ['__ref' => 'a.b.c']    → reference to another Terraform resource
 *
 * decode() turns a spec value back into a renderable value, registering any
 * variable on the target document as a side effect.
 */
final class SpecValue
{
    public const VAR = '__var';
    public const REF = '__ref';

    public static function ref(string $reference): array
    {
        return [self::REF => $reference];
    }

    public static function decode(mixed $spec, TerraformDocument $document): mixed
    {
        if (is_array($spec) && isset($spec[self::VAR])) {
            $v = $spec[self::VAR];

            $variable = match ($v['type']) {
                'number' => TfVariable::number($v['name'], $v['default'], $v['description'], $v['sensitive']),
                'bool' => TfVariable::bool($v['name'], $v['default'], $v['description'], $v['sensitive']),
                default => TfVariable::string($v['name'], $v['default'], $v['description'], $v['sensitive']),
            };

            $document->variable($variable);

            return $variable;
        }

        if (is_array($spec) && isset($spec[self::REF])) {
            return new TfReference($spec[self::REF]);
        }

        return $spec;
    }

    /** @param array<string, mixed> $map @return array<string, TfValueInterface|mixed> */
    public static function decodeMap(array $map, TerraformDocument $document): array
    {
        return array_map(static fn($v) => self::decode($v, $document), $map);
    }
}
