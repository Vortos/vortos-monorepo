<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Rendering;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vortos\Http\Request;
use Vortos\SchedulerAdmin\Rendering\AdminTwigExtension;
use Vortos\SchedulerAdmin\Security\CsrfTokenManager;

final class AdminTwigExtensionTest extends TestCase
{
    private CsrfTokenManager $csrf;
    private RequestStack     $requestStack;
    private AdminTwigExtension $extension;

    protected function setUp(): void
    {
        $session      = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();
        $request      = new Request();
        $request->setSession($session);
        $this->requestStack->push($request);

        $this->csrf      = new CsrfTokenManager($this->requestStack);
        $this->extension = new AdminTwigExtension(
            csrf:          $this->csrf,
            requestStack:  $this->requestStack,
            assetBasePath: '/bundles/scheduler-admin/build',
        );
    }

    public function test_registers_expected_functions(): void
    {
        $names = array_map(
            static fn($f) => $f->getName(),
            $this->extension->getFunctions(),
        );

        $this->assertContains('csp_nonce',    $names);
        $this->assertContains('csrf_token',   $names);
        $this->assertContains('admin_asset',  $names);
        $this->assertContains('vite_asset',   $names);
        $this->assertContains('island_props', $names);
    }

    public function test_csrf_token_is_non_empty_string(): void
    {
        $token = $this->extension->csrfToken();

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_admin_asset_prepends_base_path(): void
    {
        $url = $this->extension->adminAsset('main.css');

        $this->assertSame('/bundles/scheduler-admin/build/main.css', $url);
    }

    public function test_admin_asset_strips_leading_slash(): void
    {
        $url = $this->extension->adminAsset('/main.css');

        $this->assertSame('/bundles/scheduler-admin/build/main.css', $url);
    }

    public function test_vite_asset_falls_back_to_admin_asset_when_no_manifest(): void
    {
        $extension = new AdminTwigExtension(
            csrf:          $this->csrf,
            requestStack:  $this->requestStack,
            assetBasePath: '/build',
            manifestPath:  '/nonexistent/manifest.json',
        );

        $url = $extension->viteAsset('main.js');

        $this->assertSame('/build/main.js', $url);
    }

    public function test_island_props_produces_json_encoded_string(): void
    {
        $props  = ['flag' => 'my-flag', 'enabled' => true];
        $output = $this->extension->islandProps($props);

        $decoded = json_decode($output, true);
        $this->assertSame($props, $decoded);
    }

    public function test_island_props_escapes_html_special_chars(): void
    {
        $output = $this->extension->islandProps(['value' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('>', $output);
    }

    public function test_csp_nonce_returns_empty_string_when_no_nonce_on_request(): void
    {
        $nonce = $this->extension->cspNonce();

        $this->assertIsString($nonce);
    }

    public function test_csp_nonce_returns_nonce_from_request_attribute(): void
    {
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $request->attributes->set('_csp_nonce', 'abc123nonce');

        $rs = new RequestStack();
        $rs->push($request);

        $extension = new AdminTwigExtension($this->csrf, $rs);

        $this->assertSame('abc123nonce', $extension->cspNonce());
    }
}
