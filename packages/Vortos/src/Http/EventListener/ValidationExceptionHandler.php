<?php

declare(strict_types=1);

namespace Vortos\Http\EventListener;

use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Http\Contract\ExceptionHandlerInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidationExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(\Throwable $e, Request $request): ?Response
    {
        if (!$e instanceof ValidationException) {
            return null;
        }

        return new JsonResponse($e->toResponseArray(), 422);
    }
}
