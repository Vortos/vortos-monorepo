<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Port;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Security\SupplyChain\Model\ArtifactRef;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Model\SupplyChainException;

interface SbomGeneratorInterface extends DriverInterface
{
    /** @throws SupplyChainException on generation failure */
    public function generate(ArtifactRef $ref, SbomFormat $format): SbomDocument;
}
