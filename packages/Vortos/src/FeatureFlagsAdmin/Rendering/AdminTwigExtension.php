<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Rendering;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager;

final class AdminTwigExtension extends AbstractExtension
{
    /** Parsed Vite manifest cache (null = not yet loaded). */
    private ?array $manifest = null;

    public function __construct(
        private readonly CsrfTokenManager $csrf,
        private readonly RequestStack $requestStack,
        private readonly string $assetBasePath = '/bundles/feature-flags-admin/build',
        private readonly string $manifestPath = '',
    ) {}

    /** @return TwigFunction[] */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', [$this, 'cspNonce']),
            new TwigFunction('csrf_token', [$this, 'csrfToken']),
            new TwigFunction('admin_asset', [$this, 'adminAsset']),
            new TwigFunction('vite_asset', [$this, 'viteAsset']),
            new TwigFunction('island_props', [$this, 'islandProps'], ['is_safe' => ['html']]),
        ];
    }

    public function cspNonce(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->attributes->get('_csp_nonce', '') ?? '';
    }

    public function csrfToken(): string
    {
        return $this->csrf->getToken();
    }

    public function adminAsset(string $path): string
    {
        return $this->assetBasePath . '/' . ltrim($path, '/');
    }

    public function islandProps(array $props): string
    {
        return json_encode($props, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve a Vite-hashed asset URL from the build manifest.
     *
     * Falls back to `admin_asset($entry)` when the manifest is absent (dev/CI).
     * The manifest lives at `<manifestPath>/.vite/manifest.json` (Vite 5+).
     */
    public function viteAsset(string $entry): string
    {
        $manifest = $this->loadManifest();

        if ($manifest === null || !isset($manifest[$entry])) {
            return $this->adminAsset($entry);
        }

        return $this->assetBasePath . '/' . ltrim((string) $manifest[$entry]['file'], '/');
    }

    private function loadManifest(): ?array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->manifestPath !== ''
            ? $this->manifestPath
            : __DIR__ . '/../Public/build/.vite/manifest.json';

        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $this->manifest = is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            $this->manifest = [];
        }

        return $this->manifest ?: null;
    }
}
