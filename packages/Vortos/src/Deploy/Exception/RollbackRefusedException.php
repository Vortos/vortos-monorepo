<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

use Vortos\Release\Schema\RollbackDecision;
use Vortos\Release\Schema\RollbackRefusalReason;

final class RollbackRefusedException extends DeployException
{
    public function __construct(
        public readonly RollbackDecision $decision,
    ) {
        parent::__construct($decision->explain());
    }

    public function reason(): RollbackRefusalReason
    {
        return $this->decision->reason;
    }

    /** @return list<string> */
    public function offendingMigrations(): array
    {
        return $this->decision->offendingMigrations;
    }
}
