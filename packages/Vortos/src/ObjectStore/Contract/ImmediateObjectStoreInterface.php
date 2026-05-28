<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

/**
 * Immediate object-store provider operations with no outbox.
 *
 * Use for diagnostics, probes, provisioning, and maintenance operations where
 * the caller deliberately wants provider success/failure now.
 */
interface ImmediateObjectStoreInterface extends ObjectStoreInterface
{
}
