<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/** How a compose service may mount a declared host path (RC-3). */
enum BindAccess: string
{
    case ReadOnly = 'ro';
    case ReadWrite = 'rw';

    public function isWiderThan(self $declared): bool
    {
        return $this === self::ReadWrite && $declared === self::ReadOnly;
    }
}
