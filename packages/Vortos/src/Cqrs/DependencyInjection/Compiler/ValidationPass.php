<?php

declare(strict_types=1);

namespace Vortos\Cqrs\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Cqrs\Validation\SuppressValidationWarning;

/**
 * Emits compile-time warnings for command string properties
 * that have no Length or NotBlank constraints.
 *
 * Priority 30 — after IdempotencyKeyPass (40), command map is ready.
 * Never blocks compilation. Never throws.
 */
final class ValidationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(\Symfony\Component\Validator\Constraint::class)) {
            return;
        }

        if (!$container->hasParameter('vortos.cqrs.command_handler_map')) {
            return;
        }

        /** @var array<string, string> $map */
        $map = $container->getParameter('vortos.cqrs.command_handler_map');

        foreach (array_keys($map) as $commandClass) {
            if (!class_exists($commandClass)) {
                continue;
            }

            $reflection = new ReflectionClass($commandClass);

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (!$this->isPlainString($property)) {
                    continue;
                }

                if ($this->isSuppressed($property)) {
                    continue;
                }

                if (!$this->hasLengthOrNotBlank($property)) {
                    $container->log($this, sprintf(
                        'ValidationPass: %s::$%s is a public string with no #[Assert\Length] or ' .
                        '#[Assert\NotBlank]. Add constraints or suppress with #[SuppressValidationWarning].',
                        $commandClass,
                        $property->getName(),
                    ));
                }
            }
        }
    }

    private function isPlainString(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        // Only warn for non-nullable plain strings
        return $type->getName() === 'string' && !$type->allowsNull();
    }

    private function isSuppressed(ReflectionProperty $property): bool
    {
        return count($property->getAttributes(SuppressValidationWarning::class)) > 0;
    }

    private function hasLengthOrNotBlank(ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes() as $attr) {
            $name = $attr->getName();
            if (
                $name === \Symfony\Component\Validator\Constraints\Length::class ||
                $name === \Symfony\Component\Validator\Constraints\NotBlank::class
            ) {
                return true;
            }
        }

        return false;
    }
}
