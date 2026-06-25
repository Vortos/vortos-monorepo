<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota;

enum QuotaSubjectProvenance: string
{
    case ServerVerified = 'server_verified';
    case ClaimDerived = 'claim_derived';
}
