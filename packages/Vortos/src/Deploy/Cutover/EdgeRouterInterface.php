<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\OpsKit\Driver\DriverInterface;

interface EdgeRouterInterface extends DriverInterface
{
    public function cutover(DesiredRoute $desired): CutoverResult;

    public function liveRoute(): ?LiveRoute;

    public function reconcile(DesiredRoute $desired): ReconcileResult;
}
