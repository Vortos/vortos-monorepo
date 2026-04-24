<?php

declare(strict_types=1);

namespace Vortos\Authorization\Exception;

final class PolicyNotFoundException extends \RuntimeException
{
    public static function forResource(string $resource): self
    {
        return new self(sprintf(
            'No policy registered for resource "%s". '
                . 'Create a class with #[AsPolicy(resource: "%s")] implementing PolicyInterface.',
            $resource,
            $resource,
        ));
    }
}
