<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Sso;

final class RoleMappingException extends \RuntimeException
{
    public static function invalidPattern(string $pattern, string $pcreError): self
    {
        return new self(sprintf(
            'ClaimsRoleMapping pattern is invalid: "%s" — %s',
            $pattern,
            $pcreError,
        ));
    }

    public static function backtrackLimitExceeded(string $pattern, string $input): self
    {
        return new self(sprintf(
            'ClaimsRoleMapping pattern exceeded backtrack limit: "%s" against input length %d',
            $pattern,
            strlen($input),
        ));
    }

    public static function runtimeMatchFailure(string $pattern, string $pcreError): self
    {
        return new self(sprintf(
            'ClaimsRoleMapping regex match failed: "%s" — %s',
            $pattern,
            $pcreError,
        ));
    }
}
