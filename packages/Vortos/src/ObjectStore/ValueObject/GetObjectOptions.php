<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class GetObjectOptions
{
    public function __construct(private readonly ?ByteRange $range = null)
    {
    }

    public function range(): ?ByteRange
    {
        return $this->range;
    }
}
