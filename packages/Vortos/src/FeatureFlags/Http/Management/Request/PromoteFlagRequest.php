<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Http\Request\RequestDto;

final class PromoteFlagRequest extends RequestDto
{
    #[Assert\NotBlank]
    public string $fromEnvironment = '';

    #[Assert\NotBlank]
    public string $toEnvironment = '';
}
