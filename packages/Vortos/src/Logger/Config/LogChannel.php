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
    case Query     = 'query';
}
