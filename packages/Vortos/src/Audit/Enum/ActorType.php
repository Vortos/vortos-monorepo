<?php

declare(strict_types=1);

namespace Vortos\Audit\Enum;

/**
 * What kind of principal performed the action.
 *
 * `System` covers automated jobs (scheduler fires, retention sweeps) where there is
 * no human actor; capturing it explicitly avoids attributing machine actions to a user.
 */
enum ActorType: string
{
    case User   = 'user';
    case ApiKey = 'api_key';
    case System = 'system';
}
