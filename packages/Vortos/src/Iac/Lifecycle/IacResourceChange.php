<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final readonly class IacResourceChange
{
    public function __construct(
        public string $address,
        public string $type,
        public IacChangeAction $action,
        public string $provider,
    ) {
        if ($address === '') {
            throw new \InvalidArgumentException('Resource change address must not be empty.');
        }
    }

    public function isDestructive(): bool
    {
        return $this->action->isDestructive();
    }
}
