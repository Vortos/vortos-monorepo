<?php

declare(strict_types=1);

namespace Vortos\Authorization\Exception;

final class AccessDeniedException extends \RuntimeException
{
    public static function forbidden(string $userId, string $permission): self
    {
        return new self(sprintf(
            'User "%s" does not have permission "%s".',
            $userId,
            $permission,
        ));
    }

    public static function unauthenticated(string $permission): self
    {
        return new self(sprintf(
            'Authentication required to perform "%s".',
            $permission,
        ));
    }
}
