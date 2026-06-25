<?php

declare(strict_types=1);

namespace Vortos\Iac\Exception;

final class IacBinaryNotFoundException extends IacException
{
    public static function noneFound(): self
    {
        return new self(
            'Neither tofu nor terraform binary found on PATH. Install OpenTofu (recommended) or Terraform.',
        );
    }
}
