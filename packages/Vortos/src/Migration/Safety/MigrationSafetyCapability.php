<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum MigrationSafetyCapability: string implements CapabilityKey
{
    case AnalyzesLockSafety = 'analyzes_lock_safety';
    case ReadsLiveTableStats = 'reads_live_table_stats';
    case VerifiesConcurrently = 'verifies_concurrently';
    case UnderstandsExpandContract = 'understands_expand_contract';

    public function key(): string
    {
        return $this->value;
    }
}
