<?php

declare(strict_types=1);

namespace Vortos\Iac\Exception;

final class LocalStateForbiddenException extends \LogicException
{
    public static function forEnvironment(string $environment): self
    {
        return new self(sprintf(
            "Local Terraform state is forbidden for environment '%s'. Configure a remote+locking state backend (S3+DynamoDB, GCS, etc.).",
            $environment,
        ));
    }
}
