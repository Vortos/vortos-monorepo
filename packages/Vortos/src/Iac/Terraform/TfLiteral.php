<?php

declare(strict_types=1);

namespace Vortos\Iac\Terraform;

use Vortos\Iac\Exception\IacException;

/**
 * A literal value: scalar, or nested array/map of scalars and TfValues.
 *
 * Strings containing `${` or `%{` are escaped to `$${` / `%%{` per the
 * Terraform JSON spec, so literal data is always inert — it can never be
 * interpreted as a Terraform expression.
 */
final readonly class TfLiteral implements TfValueInterface
{
    public function __construct(public mixed $value)
    {
        $this->assertEncodable($value);
    }

    public function toJsonValue(): mixed
    {
        return self::escape($this->value);
    }

    private static function escape(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace(['${', '%{'], ['$${', '%%{'], $value);
        }

        if (is_array($value)) {
            return array_map(
                static fn($v) => $v instanceof TfValueInterface ? $v->toJsonValue() : self::escape($v),
                $value,
            );
        }

        return $value;
    }

    private function assertEncodable(mixed $value): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if ($value instanceof TfValueInterface) {
            throw new IacException('Do not wrap a TfValue in TfLiteral — pass it directly.');
        }

        if (is_array($value)) {
            foreach ($value as $v) {
                if ($v instanceof TfValueInterface) {
                    continue;
                }
                $this->assertEncodable($v);
            }
            return;
        }

        throw new IacException(sprintf(
            'TfLiteral only accepts scalars and arrays of scalars/TfValues, got %s.',
            get_debug_type($value),
        ));
    }
}
