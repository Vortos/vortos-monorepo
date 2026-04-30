<?php

declare(strict_types=1);

namespace App\User\Representation\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Http\Request\RequestDto;

final class RegisterUserRequest extends RequestDto
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 72)]
    public string $password = '';
}
