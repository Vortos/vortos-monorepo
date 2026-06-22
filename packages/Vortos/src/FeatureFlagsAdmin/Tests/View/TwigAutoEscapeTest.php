<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TwigAutoEscapeTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $viewDir = dirname(__DIR__, 2) . '/View';
        $loader = new FilesystemLoader([$viewDir]);
        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);
    }

    public function test_flag_name_with_script_injection_is_escaped(): void
    {
        $html = $this->twig->render('flags/_flag_row.html.twig', [
            'flag' => (object) [
                'flagName' => '<script>alert("xss")</script>',
                'kind' => 'release',
                'archived' => false,
                'enabled' => true,
                'ruleCount' => 0,
                'updatedAt' => '2026-01-01T00:00:00',
            ],
            'env' => 'production',
            'prefix' => '/admin/flags',
        ]);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_diff_content_is_escaped(): void
    {
        $html = $this->twig->render('history/_diff.html.twig', [
            'entry_a' => (object) ['eventId' => 'aaa<script>'],
            'entry_b' => (object) ['eventId' => 'bbb<img onerror=alert(1)>'],
            'diff_lines' => [
                ['type' => 'add', 'content' => '<script>alert("xss")</script>'],
                ['type' => 'remove', 'content' => '<img src=x onerror=alert(1)>'],
                ['type' => 'same', 'content' => 'safe content'],
            ],
            'flag_name' => 'test',
            'prefix' => '/admin/flags',
        ]);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_approval_reason_is_escaped(): void
    {
        $html = $this->twig->render('approvals/_request_list.html.twig', [
            'requests' => [
                (object) [
                    'id' => '1',
                    'flagName' => 'test',
                    'changeType' => (object) ['value' => 'enable'],
                    'environment' => 'prod',
                    'requestedBy' => 'admin',
                    'reason' => '<script>alert("xss")</script>',
                    'status' => (object) ['value' => 'pending'],
                ],
            ],
            'prefix' => '/admin/flags',
        ]);

        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function test_env_compare_flag_name_is_escaped(): void
    {
        $html = $this->twig->render('env_compare/_compare_table.html.twig', [
            'comparisons' => [
                [
                    'name' => '<img onerror=alert(1)>',
                    'status' => 'same',
                    'a' => null,
                    'b' => null,
                ],
            ],
            'env_a' => 'staging',
            'env_b' => 'production',
            'prefix' => '/admin/flags',
        ]);

        $this->assertStringContainsString('&lt;img onerror=alert(1)&gt;', $html);
    }
}
