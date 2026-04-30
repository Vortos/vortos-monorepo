<?php

declare(strict_types=1);

namespace App\Post\Application\Command;

use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Vortos\Cqrs\Attribute\AsCommandHandler;

#[AsCommandHandler()]
final class CreatePostCommandHandler 
{
    public function __construct(private LoggerInterface $logger)
    {}

    public function __invoke(CreatePost $command): string
    {
        $this->logger->alert("Post Created");

        return '';
    }
}
