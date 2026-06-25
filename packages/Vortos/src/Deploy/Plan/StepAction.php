<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

enum StepAction: string
{
    case RunMigrations = 'run-migrations';
    case DrainWorker = 'drain-worker';
    case StartWorker = 'start-worker';
    case PullImage = 'pull-image';
    case StartContainer = 'start-container';
    case StopContainer = 'stop-container';
    case CheckHealth = 'check-health';
    case RunSmoke = 'run-smoke';
    case SwitchUpstream = 'switch-upstream';
    case UpdateState = 'update-state';
    case WaitDrain = 'wait-drain';
    case WeightedRoute = 'weighted-route';
    case Noop = 'noop';
}
