<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Contract\ContractReadinessInterface;
use Vortos\Deploy\Contract\ManualReadiness;
use Vortos\Deploy\Testing\ContractReadinessConformanceTestCase;

final class ManualReadinessConformanceTest extends ContractReadinessConformanceTestCase
{
    protected function createReadiness(): ContractReadinessInterface
    {
        return new ManualReadiness();
    }

    protected function expectedKey(): string
    {
        return 'manual';
    }
}
