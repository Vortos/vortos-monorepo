<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

final class ObjectStoreOperation
{
    private readonly string $name;
    private readonly ?ObjectStoreOperationName $typedName;

    /** @param array<string, mixed> $context */
    public function __construct(
        ObjectStoreOperationName|string $name,
        private readonly array $context = [],
    ) {
        $this->typedName = $name instanceof ObjectStoreOperationName ? $name : ObjectStoreOperationName::tryFrom($name);
        $this->name = $name instanceof ObjectStoreOperationName ? $name->value : $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function typedName(): ?ObjectStoreOperationName
    {
        return $this->typedName;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
