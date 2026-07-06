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
        // Latest v3. Still Node 20 upstream (no Node 24 release yet) — surfaced as advisory by the verifier.
        return new PinnedAction('docker', 'setup-buildx-action', '8d2750c68a42422c14e847fe6c8ac0403b4cbd6f', 'v3');
    }

    public static function setupQemu(): PinnedAction
    {
        return new PinnedAction('docker', 'setup-qemu-action', 'c7c53464625b32c7a7e944ae62b3e17d2b600130', 'v3');
    }

    public static function dockerLogin(): PinnedAction
    {
        return new PinnedAction('docker', 'login-action', 'c94ce9fb468520275223c153574b00df6fe4bcc9', 'v3');
    }

    public static function buildPush(): PinnedAction
    {
        // v6 is the current major.
        return new PinnedAction('docker', 'build-push-action', '10e90e3645eae34f1e60eeb005ba3a3d33f178e8', 'v6');
    }

    public static function sbomAttest(): PinnedAction
    {
        return new PinnedAction('anchore', 'sbom-action', 'd8a2c0130026bf585de5c176ab8f7ce62d75bf04', 'v0.20.7');
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
        ];
    }
}
