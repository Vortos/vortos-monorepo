<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Builder;

use Vortos\Pipeline\Model\PinnedAction;

final class KnownActionFactory
{
    public static function checkout(): PinnedAction
    {
        // v5 runs on Node 24 (v4 ran on the now-deprecated Node 20).
        return new PinnedAction('actions', 'checkout', '93cb6efe18208431cddfb8368fd83d5badbf9bfd', 'v5');
    }

    public static function setupPhp(): PinnedAction
    {
        // v2 (current) runs on Node 24 as of this SHA.
        return new PinnedAction('shivammathur', 'setup-php', 'f3e473d116dcccaddc5834248c87452386958240', 'v2');
    }

    public static function setupNode(): PinnedAction
    {
        // v5 runs on Node 24 (v4 ran on Node 20).
        return new PinnedAction('actions', 'setup-node', 'a0853c24544627f65ddf259abe73b1d18a591444', 'v5');
    }

    public static function monorepoSplit(): PinnedAction
    {
        // Docker-runtime action (no Node runtime); latest release.
        return new PinnedAction('danharrin', 'monorepo-split-github-action', '14e42e2437f674b8987c1f50ca3689116aea1893', 'v2.4.5');
    }

    public static function setupBuildx(): PinnedAction
    {
        // v4.2.0 runs on Node 24 (v3 ran on the now-deprecated Node 20).
        return new PinnedAction('docker', 'setup-buildx-action', 'bb05f3f5519dd87d3ba754cc423b652a5edd6d2c', 'v4.2.0');
    }

    public static function setupQemu(): PinnedAction
    {
        // v4.2.0 runs on Node 24 (v3 ran on Node 20).
        return new PinnedAction('docker', 'setup-qemu-action', '96fe6ef7f33517b61c61be40b68a1882f3264fb8', 'v4.2.0');
    }

    public static function dockerLogin(): PinnedAction
    {
        // v4.4.0 runs on Node 24 (v3 ran on Node 20).
        return new PinnedAction('docker', 'login-action', 'af1e73f918a031802d376d3c8bbc3fe56130a9b0', 'v4.4.0');
    }

    public static function buildPush(): PinnedAction
    {
        // v7.3.0 runs on Node 24 natively. The whole v6 line still targets node20 (the moving `v6`
        // tag never moved off it), so GitHub force-runs it on Node 24 with a deprecation warning —
        // bumping to v7 is the only way off Node 20 for this action. (R8-7 / B1)
        return new PinnedAction('docker', 'build-push-action', '53b7df96c91f9c12dcc8a07bcb9ccacbed38856a', 'v7.3.0');
    }

    public static function sbomAttest(): PinnedAction
    {
        // v0.24.0 (Node 24). Reverses the accidental downgrade to v0.20.7 by an earlier emitter bump.
        return new PinnedAction('anchore', 'sbom-action', 'e22c389904149dbc22b58101806040fa8d37a610', 'v0.24.0');
    }

    public static function cosignInstaller(): PinnedAction
    {
        // Keyless (Sigstore/Fulcio) image signing in the build job. v3.9.1 (Node 20 runtime). SHA is the
        // dereferenced commit for refs/tags/v3.9.1, verified against sigstore/cosign-installer upstream.
        return new PinnedAction('sigstore', 'cosign-installer', '398d4b0eeef1380460a10c8013a76f728fb906ac', 'v3.9.1');
    }

    public static function trivyImageScan(): PinnedAction
    {
        // CVE scan-and-fail gate on the pushed image. v0.36.0. SHA is the dereferenced commit for
        // refs/tags/v0.36.0, verified against aquasecurity/trivy-action upstream.
        return new PinnedAction('aquasecurity', 'trivy-action', 'ed142fd0673e97e23eac54620cfb913e5ce36c25', 'v0.36.0');
    }

    /** @return list<PinnedAction> */
    public static function all(): array
    {
        return [
            self::checkout(),
            self::setupPhp(),
            self::setupNode(),
            self::monorepoSplit(),
            self::setupBuildx(),
            self::setupQemu(),
            self::dockerLogin(),
            self::buildPush(),
            self::sbomAttest(),
            self::cosignInstaller(),
            self::trivyImageScan(),
        ];
    }
}
