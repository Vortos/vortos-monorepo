<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

final class KubeApiConflictException extends \RuntimeException
{
    public static function staleResourceVersion(string $resource, string $expected): self
    {
        return new self(sprintf(
            'Conflict: resource "%s" has been modified (expected resourceVersion "%s"). Retry with a fresh read.',
            $resource,
            $expected,
        ));
    }
}
