<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Schema\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class MongoCollection
{
    public function __construct(public readonly string $name) {}
}
