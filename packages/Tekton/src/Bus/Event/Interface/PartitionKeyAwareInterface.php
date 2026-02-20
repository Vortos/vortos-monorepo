<?php

namespace Fortizan\Tekton\Bus\Event\Interface;

interface PartitionKeyAwareInterface
{
    public function getPartitionKey():string;
}