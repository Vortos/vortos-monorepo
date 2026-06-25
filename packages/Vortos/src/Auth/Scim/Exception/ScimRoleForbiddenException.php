<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Exception;

final class ScimRoleForbiddenException extends \RuntimeException
{
    public function __construct(
        public readonly string $role,
        public readonly string $tokenId,
    ) {
        parent::__construct(sprintf(
            'SCIM token "%s" is not permitted to provision role "%s".',
            $tokenId,
            $role,
        ));
    }
}
