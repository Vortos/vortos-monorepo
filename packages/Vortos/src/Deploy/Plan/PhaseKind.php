<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

enum PhaseKind: string
{
    case ExpandMigrate = 'expand-migrate';
    case RollWorkers = 'roll-workers';
    case ReconcileEdge = 'reconcile-edge';
    case StageColor = 'stage-color';
    case HealthGate = 'health-gate';
    case Smoke = 'smoke';
    case Cutover = 'cutover';
    case Promote = 'promote';
    case ContractGuard = 'contract-guard';
    case Rollback = 'rollback';
}
