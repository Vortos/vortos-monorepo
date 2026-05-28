<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Template;

use Vortos\AwsSes\Contract\TemplateRendererInterface;
use Vortos\AwsSes\Exception\TemplateNotFoundException;
use Vortos\AwsSes\Exception\TemplateRenderException;
use Vortos\AwsSes\ValueObject\RenderedEmail;

/**
 * Renders PHP file templates from a configured templates directory.
 *
 * Template resolution:
 *   HTML  — {templateDir}/{name}.html.php
 *   Text  — {templateDir}/{name}.text.php  (optional; null when absent)
 *
 * Each template file is executed in an isolated scope with the given $data
 * array extracted into local variables via extract(). The file must output
 * the rendered content — it is captured with output buffering.
 *
 * Example:
 *   // templates/emails/welcome.html.php
 *   <h1>Welcome, <?= htmlspecialchars($name) ?></h1>
 *
 *   $renderer->render('emails/welcome', ['name' => 'Alice'])
 */
final class PhpTemplateRenderer implements TemplateRendererInterface
{
    public function __construct(private readonly string $templateDir) {}

    public function render(string $template, array $data = []): RenderedEmail
    {
        $htmlFile = rtrim($this->templateDir, '/') . '/' . $template . '.html.php';
        $textFile = rtrim($this->templateDir, '/') . '/' . $template . '.text.php';

        if (!file_exists($htmlFile)) {
            throw new TemplateNotFoundException(
                sprintf('Email template not found: %s (looked for: %s)', $template, $htmlFile),
            );
        }

        $htmlBody = $this->renderFile($htmlFile, $data);
        $textBody = file_exists($textFile) ? $this->renderFile($textFile, $data) : null;

        return new RenderedEmail($htmlBody, $textBody);
    }

    private function renderFile(string $file, array $data): string
    {
        try {
            $render = static function (string $_file, array $_data): string {
                extract($_data, EXTR_SKIP);
                ob_start();
                try {
                    include $_file;
                } catch (\Throwable $e) {
                    ob_end_clean();
                    throw $e;
                }
                return (string) ob_get_clean();
            };

            return $render($file, $data);
        } catch (\Throwable $e) {
            throw new TemplateRenderException(
                sprintf('Error rendering template "%s": %s', $file, $e->getMessage()),
                previous: $e,
            );
        }
    }
}
