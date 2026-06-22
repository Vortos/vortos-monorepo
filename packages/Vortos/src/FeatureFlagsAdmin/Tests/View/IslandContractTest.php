<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\View;

use PHPUnit\Framework\TestCase;

/**
 * Block 31 — React island contract tests.
 *
 * These tests verify the PHP side of the island protocol without running Node:
 *   1. Every Twig template that contains a data-island="<name>" mount point must
 *      also provide a companion <script type="application/json"> whose id matches
 *      the data-props-id attribute.
 *   2. The AdminTwigExtension::viteAsset() falls back gracefully when the manifest
 *      is absent (dev / CI without a built bundle).
 *   3. island_props() output is JSON-safe (no raw HTML injection).
 */
final class IslandContractTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = __DIR__ . '/../../View';
    }

    // -------------------------------------------------------------------------
    // Mount-point contract
    // -------------------------------------------------------------------------

    public function test_every_island_mount_point_has_companion_props_script(): void
    {
        $pattern = 'data-island=';
        $failed  = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->viewDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (!str_contains($content, $pattern)) {
                continue;
            }

            // Extract every data-island="…" and its data-props-id="…" pair
            preg_match_all('/data-island="([^"]+)"/', $content, $islandMatches);
            preg_match_all('/data-props-id="([^"]+)"/', $content, $propsMatches);

            foreach ($islandMatches[1] as $idx => $islandName) {
                // There must be a corresponding data-props-id
                if (!isset($propsMatches[1][$idx])) {
                    $failed[] = sprintf('%s: island "%s" has no companion data-props-id', $file->getFilename(), $islandName);
                    continue;
                }

                $propsId = $propsMatches[1][$idx];

                // There must be a <script type="application/json" id="<propsId>"> in the same template
                if (!preg_match('/type="application\/json"[^>]*id="' . preg_quote($propsId, '/') . '"/', $content)
                    && !preg_match('/id="' . preg_quote($propsId, '/') . '"[^>]*type="application\/json"/', $content)) {
                    $failed[] = sprintf(
                        '%s: island "%s" references props-id "%s" but no <script type="application/json" id="%s"> found',
                        $file->getFilename(),
                        $islandName,
                        $propsId,
                        $propsId,
                    );
                }
            }
        }

        $this->assertEmpty($failed, implode("\n", $failed));
    }

    public function test_known_island_names_are_registered(): void
    {
        $knownIslands = ['rule-builder', 'insights-chart'];

        // Collect every data-island value used across templates
        $usedIslands = [];
        $iterator    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->viewDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            preg_match_all('/data-island="([^"]+)"/', $content, $matches);

            foreach ($matches[1] as $name) {
                $usedIslands[$name] = true;
            }
        }

        foreach (array_keys($usedIslands) as $used) {
            $this->assertContains(
                $used,
                $knownIslands,
                sprintf('Island "%s" is used in a template but not registered in main.tsx ISLANDS map.', $used),
            );
        }
    }

    // -------------------------------------------------------------------------
    // AdminTwigExtension — vite_asset fallback
    // -------------------------------------------------------------------------

    public function test_vite_asset_falls_back_to_admin_asset_when_no_manifest(): void
    {
        $ext = $this->makeExtension('/nonexistent/manifest.json');

        // Should not throw; falls back to adminAsset()
        $result = $ext->viteAsset('src/main.tsx');

        $this->assertStringContainsString('main', $result);
        $this->assertStringStartsWith('/build/flags-admin', $result);
    }

    public function test_vite_asset_resolves_hashed_filename_from_manifest(): void
    {
        $manifest = [
            'src/main.tsx' => ['file' => 'assets/islands-AbCdEf.js', 'src' => 'src/main.tsx', 'isEntry' => true],
        ];

        $manifestPath = sys_get_temp_dir() . '/test-vite-manifest-' . uniqid() . '.json';
        file_put_contents($manifestPath, json_encode($manifest));

        try {
            $ext    = $this->makeExtension($manifestPath);
            $result = $ext->viteAsset('src/main.tsx');

            $this->assertStringContainsString('islands-AbCdEf.js', $result);
        } finally {
            @unlink($manifestPath);
        }
    }

    public function test_vite_asset_falls_back_for_unknown_entry_in_manifest(): void
    {
        $manifest = ['src/main.tsx' => ['file' => 'assets/islands-AbCdEf.js']];

        $manifestPath = sys_get_temp_dir() . '/test-vite-manifest-unknown-' . uniqid() . '.json';
        file_put_contents($manifestPath, json_encode($manifest));

        try {
            $ext    = $this->makeExtension($manifestPath);
            $result = $ext->viteAsset('src/nonexistent.tsx');

            $this->assertStringStartsWith('/build/flags-admin', $result);
        } finally {
            @unlink($manifestPath);
        }
    }

    // -------------------------------------------------------------------------
    // island_props() XSS safety
    // -------------------------------------------------------------------------

    public function test_island_props_encodes_html_special_chars(): void
    {
        $ext    = $this->makeExtension('/nonexistent/manifest.json');
        $result = $ext->islandProps(['key' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('\\u003C', $result);
    }

    public function test_island_props_encodes_ampersand(): void
    {
        $ext    = $this->makeExtension('/nonexistent/manifest.json');
        $result = $ext->islandProps(['url' => 'https://example.com?a=1&b=2']);

        $this->assertStringNotContainsString('&', $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeExtension(string $manifestPath): \Vortos\FeatureFlagsAdmin\Rendering\AdminTwigExtension
    {
        // CsrfTokenManager is final — build it with a stub session instead of mocking.
        $session = $this->createStub(\Symfony\Component\HttpFoundation\Session\SessionInterface::class);
        $session->method('get')->willReturn('test-token');

        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);

        $stack = new \Symfony\Component\HttpFoundation\RequestStack();
        $stack->push($request);

        $csrf = new \Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager($stack);

        return new \Vortos\FeatureFlagsAdmin\Rendering\AdminTwigExtension(
            $csrf,
            $stack,
            '/build/flags-admin',
            $manifestPath,
        );
    }
}
