<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

use Vortos\Authorization\Audit\AuthorizationAuditEntry;

interface AuthorizationAuditStoreInterface
{
    public function record(AuthorizationAuditEntry $entry): void;
}
