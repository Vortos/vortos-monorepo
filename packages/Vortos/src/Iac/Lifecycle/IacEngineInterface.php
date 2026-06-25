<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

use Vortos\OpsKit\Driver\DriverInterface;

interface IacEngineInterface extends DriverInterface
{
    public function init(IacWorkspace $ws, IacExecutionContext $ctx): void;

    public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan;

    public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult;

    public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult;

    public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan;
}
