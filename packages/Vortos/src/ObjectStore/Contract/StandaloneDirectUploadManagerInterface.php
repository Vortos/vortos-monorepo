<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

/**
 * Reliable async direct-upload lifecycle operations outside an application transaction.
 */
interface StandaloneDirectUploadManagerInterface extends DirectUploadManagerInterface
{
}
