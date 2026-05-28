<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

use Vortos\AwsSes\Exception\TemplateNotFoundException;
use Vortos\AwsSes\Exception\TemplateRenderException;
use Vortos\AwsSes\ValueObject\RenderedEmail;

/**
 * Renders local email templates into HTML and plain-text bodies.
 *
 * Template names are relative identifiers (e.g. 'welcome', 'password-reset').
 * Implementations locate the template file, render it with the given data,
 * and return a RenderedEmail containing both the HTML and text bodies.
 *
 * SES Stored Templates are intentionally NOT used — all templates live in
 * the application repository for GitOps version parity.
 */
interface TemplateRendererInterface
{
    /**
     * @param array<string, mixed> $data Variables injected into the template.
     *
     * @throws TemplateNotFoundException Template file does not exist.
     * @throws TemplateRenderException   Template threw an error during rendering.
     */
    public function render(string $template, array $data = []): RenderedEmail;
}
