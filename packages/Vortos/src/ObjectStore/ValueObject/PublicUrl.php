<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class PublicUrl
{
    public function __construct(
        private readonly ObjectKey $key,
        private readonly string $url,
    ) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Public object URL must be a valid URL.');
        }
    }

    public function key(): ObjectKey
    {
        return $this->key;
    }

    public function url(): string
    {
        return $this->url;
    }
}
