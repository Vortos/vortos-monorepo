<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Template;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Exception\TemplateNotFoundException;
use Vortos\AwsSes\Template\NullTemplateRenderer;

final class NullTemplateRendererTest extends TestCase
{
    public function test_always_throws_template_not_found(): void
    {
        $renderer = new NullTemplateRenderer();

        $this->expectException(TemplateNotFoundException::class);
        $renderer->render('any-template');
    }

    public function test_exception_message_contains_template_name(): void
    {
        $renderer = new NullTemplateRenderer();

        try {
            $renderer->render('emails/welcome');
            $this->fail('Expected TemplateNotFoundException');
        } catch (TemplateNotFoundException $e) {
            $this->assertStringContainsString('emails/welcome', $e->getMessage());
        }
    }
}
