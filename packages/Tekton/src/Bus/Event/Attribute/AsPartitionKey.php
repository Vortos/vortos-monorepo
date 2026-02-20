<?php

namespace Fortizan\Tekton\Bus\Event\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class AsPartitionKey
{
    public function __construct(
        public string $key
    ){
    }
}