<?php

declare(strict_types=1);

namespace Vortos\Http\Request;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Vortos\Http\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Vortos\Cqrs\Validation\VortosValidator;

/**
 * Resolves controller arguments that are subclasses of RequestDto.
 * Registered at priority 110 — before Symfony's built-in resolvers.
 */
final class RequestDtoArgumentResolver implements ValueResolverInterface
{
    public function __construct(private readonly VortosValidator $validator)
    {
    }

    /**
     * @return iterable<RequestDto>
     */
    public function resolve(SymfonyRequest $request, ArgumentMetadata $argument): iterable
    {
        // The parameter type must match Symfony's ValueResolverInterface (contravariance).
        // The framework always builds a Vortos\Http\Request (see Runner::getRequest);
        // decline anything else rather than risk an unexpected request shape.
        if (!$request instanceof Request) {
            return;
        }

        $type = $argument->getType();

        if ($type === null || !class_exists($type) || !is_subclass_of($type, RequestDto::class)) {
            return;
        }

        yield $type::fromRequest($request, $this->validator);
    }
}
