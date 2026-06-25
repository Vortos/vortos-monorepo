<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

enum IacChangeAction: string
{
    case NoOp = 'no-op';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Replace = 'replace';
    case Read = 'read';

    public function isDestructive(): bool
    {
        return $this === self::Delete || $this === self::Replace;
    }
}
