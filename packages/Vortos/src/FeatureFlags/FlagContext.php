<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

final class FlagContext
{
    public function __construct(
        public readonly ?string $userId = null,
        public readonly array $attributes = [],
    ) {}

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
