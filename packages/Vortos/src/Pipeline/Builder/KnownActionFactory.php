<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Builder;

use Vortos\Pipeline\Model\PinnedAction;

final class KnownActionFactory
{
    public static function checkout(): PinnedAction
    {
        return new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
    }

    public static function setupPhp(): PinnedAction
    {
        return new PinnedAction('shivammathur', 'setup-php', 'c541c155eee45413f5b09a52248675b1a2575231', 'v2');
    }

    public static function setupNode(): PinnedAction
    {
        return new PinnedAction('actions', 'setup-node', '8f152de45cc393bb48ce5d89d36b731f54556e65', 'v4');
    }

    public static function monorepoSplit(): PinnedAction
    {
        return new PinnedAction('danharrin', 'monorepo-split-github-action', '14e42e2437f674b8987c1f50ca3689116aea1893', 'v2.4.5');
    }

    public static function setupBuildx(): PinnedAction
    {
        return new PinnedAction('docker', 'setup-buildx-action', '988b5a0280414f521da01fcc63a27aeeb4b104db', 'v3');
    }

    public static function setupQemu(): PinnedAction
    {
        return new PinnedAction('docker', 'setup-qemu-action', '49b3bc8e6bdd4a60e6116a5414239cba5943d3cf', 'v3');
    }

    public static function dockerLogin(): PinnedAction
    {
        return new PinnedAction('docker', 'login-action', '9780b0c442fbb1117ed29e0efdff1e18412f7567', 'v3');
    }

    public static function buildPush(): PinnedAction
    {
        return new PinnedAction('docker', 'build-push-action', 'ca052bb54ab0790a636c9b5f226502c73d547a25', 'v5');
    }

    public static function sbomAttest(): PinnedAction
    {
        return new PinnedAction('anchore', 'sbom-action', 'e22c389904149dbc22b58101806040fa8d37a610', 'v0.24.0');
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
