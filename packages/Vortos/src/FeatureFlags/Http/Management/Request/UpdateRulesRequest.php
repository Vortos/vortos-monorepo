<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Http\Request\RequestDto;

final class UpdateRulesRequest extends RequestDto
{
    #[Assert\NotNull]
    public array $rules = [];
}
