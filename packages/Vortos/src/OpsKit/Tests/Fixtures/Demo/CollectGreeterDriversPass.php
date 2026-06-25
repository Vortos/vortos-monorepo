<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

/**
 * The demo concern's collecting pass — the 1-line shape every real concern writes.
 */
final class CollectGreeterDriversPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.demo.greeter';
    public const LOCATOR_ID = 'vortos.demo.greeter_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'greeter');
    }
}
