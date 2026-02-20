<?php

namespace Fortizan\Tekton\Bus\Event\Attribute;

use Attribute;
use BackedEnum;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Header
{
    public function __construct(
        public string $channel = 'header',
        public ?string $partitionKey = null,
        public string|BackedEnum|null $topic = null,
        public string $version = 'v1',
    ) {
        if($this->topic instanceof BackedEnum){
            $this->topic = $this->topic->value;
        } 
    }
}
