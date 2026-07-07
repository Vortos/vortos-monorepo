<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * R8-1: publishes any un-published module migration stubs into the app's Doctrine migration set
 * before the deploy doctor gate. A seam so the runner can be exercised without the migration stack.
 */
interface MigrationAutoPublisherInterface
{
    /**
     * @return int number of stubs published (0 when everything was already published)
     * @throws \RuntimeException when publishing fails (the deploy must then be refused)
     */
    public function publish(): int;
}
