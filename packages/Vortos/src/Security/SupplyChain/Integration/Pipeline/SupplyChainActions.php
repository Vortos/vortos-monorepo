<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Integration\Pipeline;

use Vortos\Pipeline\Model\PinnedAction;

final class SupplyChainActions
{
    public static function cosignInstaller(): PinnedAction
    {
        // SHA is the dereferenced commit for refs/tags/v3.9.1, verified against upstream. The prior
        // pin (labelled v3.5.0) did not resolve to any real commit — a mutable-tag would have been
        // safer than a fabricated SHA, and pipeline:actions:verify would have failed closed on it.
        return new PinnedAction('sigstore', 'cosign-installer', '398d4b0eeef1380460a10c8013a76f728fb906ac', 'v3.9.1');
    }

    public static function trivyAction(): PinnedAction
    {
        // SHA is the dereferenced commit for refs/tags/v0.36.0, verified against upstream (the prior
        // v0.24.0 pin did not resolve).
        return new PinnedAction('aquasecurity', 'trivy-action', 'ed142fd0673e97e23eac54620cfb913e5ce36c25', 'v0.36.0');
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
