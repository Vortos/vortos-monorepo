<?php

declare(strict_types=1);

namespace Vortos\Backup\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectBackupTargetsPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.backup.target';
    public const LOCATOR_ID = 'vortos.backup.target_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'backup_target');
    }
}
