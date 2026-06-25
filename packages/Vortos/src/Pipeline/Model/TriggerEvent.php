<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

enum TriggerEvent: string
{
    case Push = 'push';
    case PullRequest = 'pull_request';
    case WorkflowDispatch = 'workflow_dispatch';
}
