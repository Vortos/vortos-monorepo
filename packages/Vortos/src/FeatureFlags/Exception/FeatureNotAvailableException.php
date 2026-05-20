<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exception;

use Vortos\Http\Exception\NotFoundException;

final class FeatureNotAvailableException extends NotFoundException
{
    public function __construct(string $flag)
    {
        parent::__construct("Feature '{$flag}' is not available.");
    }
}
