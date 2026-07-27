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

    /**
     * Mint a credential bound to a scope that revokes it when the scope ends.
     *
     * This is the method callers actually use — SshConnectionActivator calls it on this interface
     * — but it was only ever declared on {@see AbstractCredentialProvider}. Every implementation
     * extends that base, so it worked; the contract simply did not say so, and any implementation
     * that satisfied the interface without extending the base would have been accepted by the type
     * system and then fatal at the call site.
     */
    public function lease(EnvironmentName $env): CredentialLease;
}
