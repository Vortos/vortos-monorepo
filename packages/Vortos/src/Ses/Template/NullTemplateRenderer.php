<?php

declare(strict_types=1);

namespace Vortos\Ses\Template;

use Vortos\Ses\Contract\TemplateRendererInterface;
use Vortos\Ses\Exception\TemplateNotFoundException;
use Vortos\Ses\ValueObject\RenderedEmail;

/**
 * No-operation template renderer.
 *
 * Always throws TemplateNotFoundException. Used when no template directory
 * is configured — forces consumers to use htmlBody/textBody directly on Email.
 */
final class NullTemplateRenderer implements TemplateRendererInterface
{
    public function render(string $template, array $data = []): RenderedEmail
    {
        throw new TemplateNotFoundException(
            sprintf(
                'No template renderer is configured. Cannot render "%s". ' .
                'Set ses.template_dir in your ses.php config or use Email::htmlBody() directly.',
                $template,
            ),
        );
    }
}
