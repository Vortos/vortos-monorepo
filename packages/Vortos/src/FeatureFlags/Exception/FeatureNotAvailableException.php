<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FeatureNotAvailableException extends NotFoundHttpException
{
    public function __construct(string $flag)
    {
        parent::__construct("Feature '{$flag}' is not available.");
    }
}
