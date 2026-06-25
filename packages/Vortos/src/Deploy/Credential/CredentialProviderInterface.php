<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\OpsKit\Driver\DriverInterface;

interface CredentialProviderInterface extends DriverInterface
{
    public function issue(EnvironmentName $env): IssuedCredential;

    /**
     * Non-mutating preflight: prove the provider *could* mint in $env — config
     * present, signer/OIDC source configured, backing secret available — **without
     * minting a credential or leaving any artifact**.
     *
     * This is what 'deploy:doctor' calls instead of {@see issue()}, so a preflight
     * never creates standing or ephemeral secrets just to check (preserving the
     * Block 11 zero-standing-secret invariant). A real mint still happens later, at
     * deploy time, via {@see issue()}/lease.
     *
     * @throws \Vortos\Deploy\Exception\CredentialNotIssuableException when a mint would fail
     */
    public function assertIssuable(EnvironmentName $env): void;
}
