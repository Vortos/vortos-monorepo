<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver;

use Vortos\OpsKit\Driver\Exception\UnknownDriverException;

/**
 * Resolves a driver by its stable string key.
 *
 * One registry exists per concern (a typed subclass of {@see TaggedDriverRegistry}).
 * The map is built once, at compile time, by the concern's collecting compiler pass;
 * resolution at runtime is a keyed lookup with no reflection.
 */
interface DriverRegistryInterface
{
    /**
     * @throws UnknownDriverException when no driver is registered under $key
     */
    public function get(string $key): object;

    public function has(string $key): bool;

    /** @return list<string> all registered driver keys, for diagnostics */
    public function keys(): array;
}
