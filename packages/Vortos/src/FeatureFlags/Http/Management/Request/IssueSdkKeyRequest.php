<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\FeatureFlags\SdkKey\SdkKey;
use Vortos\Http\Request\RequestDto;

final class IssueSdkKeyRequest extends RequestDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    public string $name = '';

    #[Assert\NotBlank]
    public string $projectId = '';

    #[Assert\NotBlank]
    public string $environment = '';

    public string $kind = SdkKey::KIND_SERVER;

    public ?array $ipAllowlist = null;

    public ?\DateTimeImmutable $expiresAt = null;
}
