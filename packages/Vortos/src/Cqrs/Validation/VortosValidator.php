<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Validation;

use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Thin wrapper around symfony/validator.
 * Userland never touches ValidatorInterface directly.
 *
 * ValidatorInterface is injected by the DI container so it uses the PSR-6
 * metadata cache wired by Symfony's FrameworkBundle (or the Vortos cache
 * package). Building a standalone validator via Validation::createValidatorBuilder()
 * skips the cache entirely — every request re-parses attribute metadata via
 * reflection, which is measurably slow on large command objects.
 */
final class VortosValidator
{
    public function __construct(private readonly ValidatorInterface $validator) {}

    /** @param string[]|string|null $groups */
    public function validate(object $object, array|string|null $groups = null): ConstraintViolationListInterface
    {
        return $this->validator->validate($object, null, $groups);
    }

    /**
     * @param string[]|string|null $groups
     * @throws ValidationException
     */
    public function validateOrThrow(object $object, array|string|null $groups = null): void
    {
        $violations = $this->validate($object, $groups);

        if (count($violations) > 0) {
            throw new ValidationException($violations);
        }
    }

    /**
     * Returns true if the object has any constraint attributes on its properties.
     * Used by CommandBus for memoized per-class skipping.
     */
    public function hasConstraints(object $object): bool
    {
        $metadata = $this->validator->getMetadataFor($object);

        if (!$metadata instanceof ClassMetadata) {
            return false;
        }

        foreach ($metadata->getConstrainedProperties() as $propertyName) {
            foreach ($metadata->getPropertyMetadata($propertyName) as $pm) {
                if (count($pm->getConstraints()) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
