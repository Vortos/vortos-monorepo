<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Credential\Governance\DeployChangeRequest;
use Vortos\Deploy\Credential\Governance\DeployChangeRequestStoreInterface;

final class FakeDeployChangeRequestStore implements DeployChangeRequestStoreInterface
{
    /** @var array<string, DeployChangeRequest> */
    private array $approved = [];

    public function findApprovedForEnvironment(string $env): ?DeployChangeRequest
    {
        return $this->approved[$env] ?? null;
    }

    public function setApproved(string $env, DeployChangeRequest $cr): void
    {
        $this->approved[$env] = $cr;
    }

    public function clearApproved(string $env): void
    {
        unset($this->approved[$env]);
    }
}
