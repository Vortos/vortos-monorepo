<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

enum StageKind: string
{
    case Test = 'test';
    case StaticAnalysis = 'static-analysis';
    case Agnosticism = 'agnosticism';
    case Security = 'security';
    case MigrationDryRun = 'migration-dry-run';
    case Build = 'build';
    case IacPlan = 'iac-plan';
    case Deploy = 'deploy';
    case Split = 'split';
}
