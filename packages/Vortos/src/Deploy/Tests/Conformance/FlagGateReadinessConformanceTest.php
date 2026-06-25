<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Contract\ContractReadinessInterface;
use Vortos\Deploy\Contract\FlagGateReadiness;
use Vortos\Deploy\Testing\ContractReadinessConformanceTestCase;
use Vortos\Migration\Schema\FlagGateMetadataReaderInterface;

final class FlagGateReadinessConformanceTest extends ContractReadinessConformanceTestCase
{
    protected function createReadiness(): ContractReadinessInterface
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $flagGateReader->method('flagGateFor')->willReturn(null);

        return new FlagGateReadiness($flagGateReader);
    }

    protected function expectedKey(): string
    {
        return 'flag-gate';
    }
}
