<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Template;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Exception\TemplateNotFoundException;
use Vortos\AwsSes\Exception\TemplateRenderException;
use Vortos\AwsSes\Template\PhpTemplateRenderer;

final class PhpTemplateRendererTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/vortos_aws_ses_tpl_' . uniqid();
        mkdir($this->templateDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp template files
        foreach (glob($this->templateDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->templateDir);
    }

    private function makeRenderer(): PhpTemplateRenderer
    {
        return new PhpTemplateRenderer($this->templateDir);
    }

    private function writeTemplate(string $name, string $content): void
    {
        file_put_contents($this->templateDir . '/' . $name, $content);
    }

    public function test_renders_html_template(): void
    {
        $this->writeTemplate('welcome.html.php', '<h1>Hello</h1>');

        $result = $this->makeRenderer()->render('welcome');

        $this->assertSame('<h1>Hello</h1>', $result->htmlBody());
    }

    public function test_injects_data_variables(): void
    {
        $this->writeTemplate('greeting.html.php', '<p><?= htmlspecialchars($name) ?></p>');

        $result = $this->makeRenderer()->render('greeting', ['name' => 'Alice']);

        $this->assertSame('<p>Alice</p>', $result->htmlBody());
    }

    public function test_text_body_null_when_text_template_missing(): void
    {
        $this->writeTemplate('html-only.html.php', '<p>HTML</p>');

        $result = $this->makeRenderer()->render('html-only');

        $this->assertNull($result->textBody());
    }

    public function test_renders_text_template_when_present(): void
    {
        $this->writeTemplate('both.html.php', '<p>HTML</p>');
        $this->writeTemplate('both.text.php', 'Plain text');

        $result = $this->makeRenderer()->render('both');

        $this->assertSame('Plain text', $result->textBody());
    }

    public function test_text_template_also_receives_data(): void
    {
        $this->writeTemplate('email.html.php', '<p>HTML</p>');
        $this->writeTemplate('email.text.php', '<?= $name ?>');

        $result = $this->makeRenderer()->render('email', ['name' => 'Bob']);

        $this->assertSame('Bob', $result->textBody());
    }

    public function test_throws_not_found_when_html_template_missing(): void
    {
        $this->expectException(TemplateNotFoundException::class);
        $this->makeRenderer()->render('nonexistent');
    }

    public function test_not_found_exception_contains_template_name(): void
    {
        try {
            $this->makeRenderer()->render('missing/template');
            $this->fail('Expected TemplateNotFoundException');
        } catch (TemplateNotFoundException $e) {
            $this->assertStringContainsString('missing/template', $e->getMessage());
        }
    }

    public function test_render_exception_wraps_template_errors(): void
    {
        $this->writeTemplate('broken.html.php', '<?php throw new \RuntimeException("template error"); ?>');

        $this->expectException(TemplateRenderException::class);
        $this->makeRenderer()->render('broken');
    }

    public function test_render_exception_contains_original_error_message(): void
    {
        $this->writeTemplate('err.html.php', '<?php throw new \RuntimeException("boom"); ?>');

        try {
            $this->makeRenderer()->render('err');
            $this->fail('Expected TemplateRenderException');
        } catch (TemplateRenderException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function test_supports_subdirectory_templates(): void
    {
        $subDir = $this->templateDir . '/emails';
        mkdir($subDir, 0755, true);
        $file = $subDir . '/welcome.html.php';
        file_put_contents($file, '<p>Sub</p>');

        $result = $this->makeRenderer()->render('emails/welcome');

        $this->assertSame('<p>Sub</p>', $result->htmlBody());

        unlink($file);
        rmdir($subDir);
    }

    public function test_data_is_isolated_between_renders(): void
    {
        $this->writeTemplate('isolated.html.php', '<?= $value ?>');

        $r1 = $this->makeRenderer()->render('isolated', ['value' => 'first']);
        $r2 = $this->makeRenderer()->render('isolated', ['value' => 'second']);

        $this->assertSame('first',  $r1->htmlBody());
        $this->assertSame('second', $r2->htmlBody());
    }
}
