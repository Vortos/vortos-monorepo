<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Sbom;

enum SbomFormat: string
{
    case CycloneDxJson = 'cyclonedx-json';
    case SpdxJson = 'spdx-json';
}
