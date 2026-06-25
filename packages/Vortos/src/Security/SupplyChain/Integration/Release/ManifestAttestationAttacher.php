<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Integration\Release;

use Vortos\Release\Manifest\BuildManifest;
use Vortos\Security\SupplyChain\Model\Attestation\AttestationBundle;
use Vortos\Security\SupplyChain\Service\SupplyChainManifestDecorator;

final class ManifestAttestationAttacher
{
    public function __construct(
        private readonly SupplyChainManifestDecorator $decorator,
    ) {}

    public function attach(BuildManifest $manifest, AttestationBundle $bundle): BuildManifest
    {
        return $this->decorator->decorate($manifest, $bundle);
    }
}
