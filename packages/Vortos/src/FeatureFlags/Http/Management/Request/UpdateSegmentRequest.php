<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Http\Request\RequestDto;

final class UpdateSegmentRequest extends RequestDto
{
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 2000)]
    public ?string $description = null;

    public ?array $rules = null;
}
