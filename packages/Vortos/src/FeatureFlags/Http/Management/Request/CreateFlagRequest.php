<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\Http\Request\RequestDto;

final class CreateFlagRequest extends RequestDto
{
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z][a-z0-9_-]{0,127}$/')]
    public string $name = '';

    #[Assert\Length(max: 2000)]
    public string $description = '';

    public ?string $kind = null;

    public ?string $valueType = null;

    public ?string $bucketBy = null;

    public ?string $owner = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    public string $projectId = ProjectContext::DEFAULT_PROJECT;

    public ?string $environment = null;
}
