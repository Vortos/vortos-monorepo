<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Vortos\Http\Request\RequestDto;

final class UpdateScheduleRequest extends RequestDto
{
    public ?array $schedule = null;
}
