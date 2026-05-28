<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Exception;

final class TemplateNotFoundException extends \RuntimeException
{
    public static function forTemplate(string $template, string $templateDir): self
    {
        return new self(sprintf(
            'Email template "%s" not found. Expected file at: %s/%s.html.php',
            $template,
            rtrim($templateDir, '/'),
            $template,
        ));
    }
}
