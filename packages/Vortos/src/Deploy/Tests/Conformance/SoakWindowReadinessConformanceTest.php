<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Contract\ContractReadinessInterface;
use Vortos\Deploy\Contract\SoakWindowReadiness;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Testing\ContractReadinessConformanceTestCase;

final class SoakWindowReadinessConformanceTest extends ContractReadinessConformanceTestCase
{
    protected function createReadiness(): ContractReadinessInterface
    {
        $stateStore = new FakeDeployStateStore();

        return new SoakWindowReadiness($stateStore, $stateStore);
    }

    protected function expectedKey(): string
    {
        return 'soak-window';
    }
}
