<?php

declare(strict_types=1);

namespace Vortos\Domain\Error;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class HttpStatus
{
    public function __construct(public readonly int $status) {}
}
