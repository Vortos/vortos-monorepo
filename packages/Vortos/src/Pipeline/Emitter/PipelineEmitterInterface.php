<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Emitter;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Pipeline\Model\Pipeline;

interface PipelineEmitterInterface extends DriverInterface
{
    public function emit(Pipeline $pipeline): EmittedArtifactSet;
}
