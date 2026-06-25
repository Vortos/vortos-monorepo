<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class CredentialGovernanceException extends DeployException
{
    public static function noApprovedChangeRequest(string $env): self
    {
        return new self(sprintf(
            'Protected environment "%s" requires an approved change request (4-eyes) before credentials can be issued.',
            $env,
        ));
    }

    public static function selfApproval(): self
    {
        return new self('Self-approval is not permitted for credential issuance on protected environments.');
    }
}
