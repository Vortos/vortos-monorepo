<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Exception;

final class TemplateRenderException extends \RuntimeException
{
    public static function forTemplate(string $template, \Throwable $previous): self
    {
        return new self(
            sprintf('Failed to render email template "%s": %s', $template, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
