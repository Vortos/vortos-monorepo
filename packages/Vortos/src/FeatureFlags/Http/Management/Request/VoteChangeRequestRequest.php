<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Http\Request\RequestDto;

final class VoteChangeRequestRequest extends RequestDto
{
    public bool $approve = true;

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 2000)]
    public string $reason = '';
}
