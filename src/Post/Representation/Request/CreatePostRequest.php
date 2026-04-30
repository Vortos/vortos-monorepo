<?php

declare(strict_types=1);

namespace App\Post\Representation\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Http\Request\RequestDto;

final class CreatePostRequest extends RequestDto
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $requestId = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 200)]
    public string $title = '';

    #[Assert\NotBlank]
    public string $body = '';

    public bool $published = false;
}
