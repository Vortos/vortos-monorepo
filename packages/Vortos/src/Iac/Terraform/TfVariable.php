<?php

declare(strict_types=1);

namespace Vortos\Iac\Terraform;

use Vortos\Iac\Exception\IacException;

/**
 * A Terraform input variable and the `${var.name}` reference to it.
 *
 * This is how Env references reach Terraform: the variable carries name,
 * type, optional default, and a sensitive flag — never a value. The operator
 * supplies values through terraform.tfvars / CI variables, which is where
 * secret handling belongs.
 */
final readonly class TfVariable implements TfValueInterface
{
    private const NAME_PATTERN = '/^[a-z_][a-z0-9_]*$/';

    /**
     * Attribute names that suggest secret content. Variables matching this
     * are marked sensitive; literals matching it are rejected outright by
     * the document's secret gate.
     */
    public const SECRET_NAME_PATTERN
        = '/(password|passwd|secret|token|credential|private_key|api_key|access_key|sasl)/i';

    private function __construct(
        public string $name,
        /** 'string' | 'number' | 'bool' */
        public string $type,
        public string|int|float|bool|null $default,
        public string $description,
        public bool $sensitive,
    ) {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new IacException(sprintf(
                "Invalid Terraform variable name '%s' — lowercase identifiers only.",
                $name,
            ));
        }
    }

    public static function string(string $name, ?string $default = null, string $description = '', bool $sensitive = false): self
    {
        return new self($name, 'string', $default, $description, $sensitive);
    }

    public static function number(string $name, int|float|null $default = null, string $description = '', bool $sensitive = false): self
    {
        return new self($name, 'number', $default, $description, $sensitive);
    }

    public static function bool(string $name, ?bool $default = null, string $description = '', bool $sensitive = false): self
    {
        return new self($name, 'bool', $default, $description, $sensitive);
    }

    public function toJsonValue(): string
    {
        return '${var.' . $this->name . '}';
    }

    /** The `variable` block body for the variables file. */
    public function block(): array
    {
        $block = ['type' => $this->type];

        if ($this->default !== null) {
            $block['default'] = $this->default;
        }

        if ($this->description !== '') {
            $block['description'] = $this->description;
        }

        if ($this->sensitive) {
            $block['sensitive'] = true;
        }

        return $block;
    }
}
