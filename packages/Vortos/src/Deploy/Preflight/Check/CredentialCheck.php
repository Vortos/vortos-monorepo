<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Exception\CredentialNotIssuableException;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Fail-closed: the selected credential provider must be able to mint in the target
 * environment. The check calls {@see
 * \Vortos\Deploy\Credential\CredentialProviderInterface::assertIssuable()} — a
 * non-mutating probe — and **never** {@see
 * \Vortos\Deploy\Credential\CredentialProviderInterface::issue()}, so the preflight
 * creates no standing or ephemeral secret (preserving the Block 11 invariant).
 */
final class CredentialCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly CredentialProviderRegistry $credentials,
    ) {}

    public function id(): string
    {
        return 'credential.issuable';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Credential;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $key = $context->definition->credential;

        if (!$this->credentials->has($key)) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('credential provider "%s" is not registered', $key),
                sprintf('registered: [%s]', implode(', ', $this->credentials->keys())),
                'Install the credential provider package or correct the selection in config/deploy.php.',
            );
        }

        $provider = $this->credentials->provider($key);

        try {
            $provider->assertIssuable($context->environment);
        } catch (CredentialNotIssuableException $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('credential provider "%s" cannot mint in "%s"', $key, $context->environment->value),
                $e->getMessage(),
                'Provide the missing credential configuration / backing secret for this environment.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('credential provider "%s" can mint in "%s"', $key, $context->environment->value),
        );
    }
}
