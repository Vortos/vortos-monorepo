<?php

declare(strict_types=1);

namespace App\Post\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Domain\Command\AbstractCommand;
use Vortos\Domain\Command\AsIdempotencyKey;

final readonly class CreatePost extends AbstractCommand
{
    public function __construct(
        #[AsIdempotencyKey]
        public string $requestId,

        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 200)]
        public string $title,

        #[Assert\NotBlank]
        public string $body,

        #[Assert\Uuid]
        public string $authorId,
    ) {}
}
