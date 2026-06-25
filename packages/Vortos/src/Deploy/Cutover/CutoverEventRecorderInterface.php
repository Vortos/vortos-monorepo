<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

interface CutoverEventRecorderInterface
{
    public function recordCutover(DesiredRoute $route, CutoverResult $result): void;

    public function recordRevert(DesiredRoute $failedRoute, ?DesiredRoute $revertedTo): void;

    public function recordDrift(DesiredRoute $desired, ?LiveRoute $actual): void;
}
