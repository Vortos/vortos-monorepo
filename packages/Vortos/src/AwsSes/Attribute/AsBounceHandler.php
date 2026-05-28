<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Attribute;

/**
 * Marks a BounceHandlerInterface implementation for auto-discovery.
 *
 * Tagged services are collected by BounceHandlerDiscoveryPass and injected
 * into BounceHandlerRunner. All handlers run in registration order; one
 * failing handler never blocks the others.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsBounceHandler {}
