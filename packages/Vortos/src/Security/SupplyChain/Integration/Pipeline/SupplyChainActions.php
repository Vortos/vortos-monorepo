<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Integration\Pipeline;

use Vortos\Pipeline\Model\PinnedAction;

final class SupplyChainActions
{
    public static function cosignInstaller(): PinnedAction
    {
        return new PinnedAction('sigstore', 'cosign-installer', 'dc72c7d5c4d10cd6bcb8cf6e3fd1d5dcd16e8a16', 'v3.5.0');
    }

    public static function trivyAction(): PinnedAction
    {
        return new PinnedAction('aquasecurity', 'trivy-action', '18f2510ee396bbf400402947e795f2d11cf7534c', 'v0.24.0');
    }

    public static function syftAction(): PinnedAction
    {
        return new PinnedAction('anchore', 'sbom-action', 'fc46e51fd3cb168ffb36c6d1915723c47db58571', 'v0');
    }

    /** @return list<PinnedAction> */
    public static function all(): array
    {
        return [
            self::cosignInstaller(),
            self::trivyAction(),
            self::syftAction(),
        ];
    }
}
