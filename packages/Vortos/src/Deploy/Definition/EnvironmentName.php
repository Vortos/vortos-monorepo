<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

final readonly class EnvironmentName
{
    private const PATTERN = '/^[a-z][a-z0-9-]*$/';

    public string $value;

    public function __construct(string $value)
    {
        if (!preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(sprintf(
                'Environment name must be lower-kebab (^[a-z][a-z0-9-]*$), got "%s".',
                $value,
            ));
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
