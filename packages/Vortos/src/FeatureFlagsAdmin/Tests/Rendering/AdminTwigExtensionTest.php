<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vortos\FeatureFlagsAdmin\Rendering\AdminTwigExtension;
use Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager;
use Vortos\Http\Request;

final class AdminTwigExtensionTest extends TestCase
{
    private AdminTwigExtension $ext;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($session);
        $request->attributes->set('_csp_nonce', 'test-nonce-123');
        $this->requestStack->push($request);

        $csrf = new CsrfTokenManager($this->requestStack);

        $this->ext = new AdminTwigExtension($csrf, $this->requestStack, '/build/flags-admin');
    }

    public function test_csp_nonce_returns_nonce_from_request(): void
    {
        $this->assertSame('test-nonce-123', $this->ext->cspNonce());
    }

    public function test_csrf_token_returns_token(): void
    {
        $token = $this->ext->csrfToken();
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));
    }

    public function test_admin_asset_prepends_base_path(): void
    {
        $this->assertSame('/build/flags-admin/app.js', $this->ext->adminAsset('app.js'));
    }

    public function test_admin_asset_strips_leading_slash(): void
    {
        $this->assertSame('/build/flags-admin/app.js', $this->ext->adminAsset('/app.js'));
    }

    public function test_island_props_encodes_json_safely(): void
    {
        $result = $this->ext->islandProps([
            'name' => '<script>alert("xss")</script>',
            'value' => "O'Brien & Co",
        ]);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString("'", $result);
        $this->assertStringNotContainsString('&', $result);

        $decoded = json_decode($result, true);
        $this->assertSame('<script>alert("xss")</script>', $decoded['name']);
        $this->assertSame("O'Brien & Co", $decoded['value']);
    }

    public function test_provides_expected_functions(): void
    {
        $functions = $this->ext->getFunctions();
        $names = array_map(fn($f) => $f->getName(), $functions);

        $this->assertContains('csp_nonce', $names);
        $this->assertContains('csrf_token', $names);
        $this->assertContains('admin_asset', $names);
        $this->assertContains('island_props', $names);
    }
}
