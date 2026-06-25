<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential\Governance;

interface DeployChangeRequestStoreInterface
{
    public function findApprovedForEnvironment(string $env): ?DeployChangeRequest;
}
