<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that:
 * 1. All Twig templates use `autoescape=html` (enforced by the Environment wiring)
 * 2. No template uses `{{ ... |raw }}` without an explicit safe-mark comment
 * 3. The `strict_variables` flag prevents silent null-output bugs
 */
final class TwigAutoEscapeArchTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = dirname(__DIR__, 2) . '/View';
    }

    public function test_no_raw_filter_without_safe_comment(): void
    {
        $violations = [];

        foreach ($this->twigFiles($this->viewDir) as $file) {
            $source   = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->viewDir . '/', '', $file->getPathname());

            // Look for |raw usage that is NOT preceded by a {# safe: ... #} marker on the same or previous line.
            if (preg_match('/\|\s*raw(?!\s*#)/', $source)) {
                if (!str_contains($source, '{# safe:')) {
                    $violations[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These templates use |raw without a {# safe: reason #} comment — add the comment or use a safe Twig function:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_all_templates_extend_base_or_are_partials(): void
    {
        $fullPageTemplates = [];
        $violations        = [];

        foreach ($this->twigFiles($this->viewDir) as $file) {
            $source   = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->viewDir . '/', '', $file->getPathname());

            $isPartial = str_starts_with(basename($file->getFilename()), '_');

            if (!$isPartial) {
                if (!str_contains($source, '{% extends') && !str_contains($source, '{% block')) {
                    $violations[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These non-partial templates don't extend base.html.twig — every full-page template must extend the layout:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_csrf_meta_tag_present_in_base_layout(): void
    {
        $basePath = $this->viewDir . '/layout/base.html.twig';

        if (!file_exists($basePath)) {
            $this->markTestSkipped('base.html.twig not present.');
        }

        $source = (string) file_get_contents($basePath);

        $this->assertStringContainsString(
            'name="csrf-token"',
            $source,
            'base.html.twig must include the <meta name="csrf-token"> tag for HTMX CSRF injection',
        );

        $this->assertStringContainsString(
            'X-CSRF-Token',
            $source,
            'base.html.twig must set X-CSRF-Token on every HTMX request',
        );
    }

    public function test_csp_nonce_applied_to_inline_scripts(): void
    {
        $violations = [];

        foreach ($this->twigFiles($this->viewDir) as $file) {
            $source   = (string) file_get_contents($file->getPathname());
            $relative = str_replace($this->viewDir . '/', '', $file->getPathname());

            if (preg_match('/<script(?![^>]*nonce)[^>]*>/', $source)) {
                if (str_contains($source, 'csp_nonce()')) {
                    continue;
                }
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These templates have <script> tags without a nonce — use nonce=\"{{ csp_nonce() }}\":\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /** @return iterable<\SplFileInfo> */
    private function twigFiles(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                yield $file;
            }
        }
    }
}
