<?php

declare(strict_types=1);

namespace App\Post\Representation\Controller;

use App\Post\Application\Command\CreatePost;
use App\Post\Representation\Request\CreatePostRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Auth\Attribute\CurrentUser;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Temporal\TemporalAuthorizationManager;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/posts', methods: ['POST'])]
#[RequiresAuth]
#[RequiresPermission('beta.analytics_v2.any')]
// #[RequiresPermission('posts.create.own')]
final class CreatePostController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUserProvider $userProvider,
        private TemporalAuthorizationManager $temporal
    ) {}

    public function __invoke(
        CreatePostRequest $request,  // ← auto-hydrated, coerced, validated by RequestDtoArgumentResolver
    ): JsonResponse {

        $this->temporal->grant($this->userProvider->get()->id(), 'beta.analytics_v2')->forHours(1);

        $isValid = $this->temporal->isValid($this->userProvider->get()->id(), 'beta.analytics_v2');

        $expiry = $this->temporal->getExpiry($this->userProvider->get()->id(), 'beta.analytics_v2');

        $this->commandBus->dispatch(new CreatePost(
            requestId: $request->requestId,
            title: $request->title,
            body: $request->body,
            authorId: $this->userProvider->get()->id(), // you'd get this from CurrentUserProvider
        ));

        return new JsonResponse(['status' => 'created', 'isValid' => $isValid, 'expiry' => $expiry], 201);
    }
}
