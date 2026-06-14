<?php

declare(strict_types=1);

namespace Vortos\Logger\Config;

enum LogChannel: string
{
    case App       = 'app';
    case Http      = 'http';
    case Cqrs      = 'cqrs';
    case Messaging = 'messaging';
    case Cache     = 'cache';
    case Security  = 'security';
    case Audit     = 'audit';
    case Query     = 'query';
    case Tooling   = 'tooling';

    /**
     * Channels that are always write-through (never buffered) because losing
     * a record on crash is unacceptable for compliance/forensics.
     */
    public function isWriteThroughByDefault(): bool
    {
        return match ($this) {
            self::Security, self::Audit => true,
            default => false,
        };
    }
}
