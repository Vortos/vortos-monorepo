<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialNotIssuableException;

abstract class AbstractCredentialProvider implements CredentialProviderInterface
{
    public function lease(EnvironmentName $env): CredentialLease
    {
        $credential = $this->issue($env);

        return $this->materialize($credential, $env);
    }

    /**
     * Default non-mutating preflight: assert the provider's capability descriptor is
     * well-formed (it can be built and queried) and the environment is named. This is
     * cheap and always safe — it mints nothing. Concrete providers override with a
     * real, still non-minting reachability assertion for their backing source.
     */
    public function assertIssuable(EnvironmentName $env): void
    {
        if ($env->value === '') {
            throw CredentialNotIssuableException::forProvider(
                static::class,
                'target environment is empty',
            );
        }

        try {
            $this->capabilities();
        } catch (\Throwable $e) {
            throw CredentialNotIssuableException::forProvider(
                static::class,
                'capability descriptor is malformed: ' . $e->getMessage(),
            );
        }
    }

    abstract protected function materialize(IssuedCredential $credential, EnvironmentName $env): CredentialLease;
}
