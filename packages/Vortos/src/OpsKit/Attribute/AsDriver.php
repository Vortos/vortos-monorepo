<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Attribute;

use Attribute;

/**
 * Marks a class as a named driver for some swappable concern.
 *
 * The {@see $key} is the driver's stable identity — the string selected by name
 * in `config/deploy.php` (Block 6) and the key under which the driver is resolved
 * from its concern's registry. It is read once, at container compile time, by the
 * concern's {@see \Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass}
 * — never via runtime reflection (Golden Rule #1).
 *
 * A driver is still tagged by *interface* (the concern's port), via
 * `registerForAutoconfiguration(PortInterface::class)->addTag('vortos.<concern>')`
 * in the concern's DI extension. This attribute supplies only the key, so a driver
 * is exactly: "implement the port + declare a key".
 *
 * The key must match `^[a-z][a-z0-9-]*$` (lower-kebab) — enforced at compile time.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsDriver
{
    public function __construct(public string $key) {}
}
