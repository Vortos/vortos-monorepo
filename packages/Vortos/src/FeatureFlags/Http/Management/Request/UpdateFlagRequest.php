<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Http\Request\RequestDto;

final class UpdateFlagRequest extends RequestDto
{
    #[Assert\Length(max: 2000)]
    public ?string $description = null;

    public ?string $kind = null;

    public ?string $bucketBy = null;

    public ?string $owner = null;

    public mixed $defaultValue = null;

    public ?array $payload = null;
}
