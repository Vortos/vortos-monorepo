<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectContractReadinessPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.contract_readiness';
    public const LOCATOR_ID = 'vortos.deploy.contract_readiness_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'contract-readiness');
    }
}
