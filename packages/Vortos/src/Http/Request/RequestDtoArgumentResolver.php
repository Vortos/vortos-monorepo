<?php

declare(strict_types=1);

namespace Vortos\Http\Request;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Request;
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

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();

        if ($type === null || !class_exists($type) || !is_subclass_of($type, RequestDto::class)) {
            return;
        }

        yield $type::fromRequest($request, $this->validator);
    }
}
