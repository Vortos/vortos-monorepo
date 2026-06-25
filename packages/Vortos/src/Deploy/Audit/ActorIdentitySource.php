<?php

declare(strict_types=1);

namespace Vortos\Deploy\Audit;

/**
 * Strong actor-identity binding for the audit trail (§4.3): the audited actor is
 * always bound to a verifiable identity source — never a free-text username.
 */
enum ActorIdentitySource: string
{
    case Oidc = 'oidc';
    case SshCa = 'ssh-ca';
    case Local = 'local';
}
