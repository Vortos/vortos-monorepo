<?php

namespace Vortos\Domain;

interface AggregateRootInterface
{
    public function releaseEvents():array;
}