<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\Http\Request\RequestDto;

final class CreateSegmentRequest extends RequestDto
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\Length(max: 2000)]
    public string $description = '';

    public array $rules = [];

    public string $projectId = ProjectContext::DEFAULT_PROJECT;
}
