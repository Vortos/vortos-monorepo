<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\Http\Request\RequestDto;

final class CreateChangeRequestRequest extends RequestDto
{
    #[Assert\NotBlank]
    public string $flagName = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    public string $projectId = ProjectContext::DEFAULT_PROJECT;

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 64)]
    public string $environment = '';

    public ChangeType $changeType = ChangeType::Enable;

    public array $payload = [];

    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 2000)]
    public string $reason = '';

    public ?\DateTimeImmutable $applyAt = null;
}
