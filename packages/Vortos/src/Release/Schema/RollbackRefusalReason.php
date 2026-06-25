<?php

declare(strict_types=1);

namespace Vortos\Release\Schema;

enum RollbackRefusalReason: string
{
    case Legal = 'legal';
    case TargetNotSubset = 'target_not_subset';
    case UnknownAppliedMigration = 'unknown_applied_migration';
}
