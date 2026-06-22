<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\Http\Request\RequestDto;

final class CreateGuardrailPolicyRequest extends RequestDto
{
    #[Assert\NotBlank]
    public string $flagName = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    public string $projectId = ProjectContext::DEFAULT_PROJECT;

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 64)]
    public string $environment = '';

    #[Assert\Choice(['disable', 'pause_ramp'])]
    public string $action = 'disable';

    #[Assert\Count(min: 1)]
    public array $conditions = [];

    #[Assert\Range(min: 1, max: 100)]
    public int $consecutiveWindows = 2;

    #[Assert\Range(min: 1, max: 86400)]
    public int $windowSeconds = 300;

    #[Assert\Range(min: 0, max: 86400)]
    public int $cooldownSeconds = 600;

    #[Assert\Range(min: 0, max: 100)]
    public ?int $pauseRampTargetPct = null;

    public bool $ackRequired = false;
}
