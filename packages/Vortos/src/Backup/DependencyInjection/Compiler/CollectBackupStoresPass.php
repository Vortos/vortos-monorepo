<?php

declare(strict_types=1);

namespace Vortos\Backup\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectBackupStoresPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.backup.store';
    public const LOCATOR_ID = 'vortos.backup.store_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'backup_store');
    }
}
