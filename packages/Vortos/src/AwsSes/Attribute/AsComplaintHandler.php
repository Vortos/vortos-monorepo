<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Attribute;

/**
 * Marks a ComplaintHandlerInterface implementation for auto-discovery.
 *
 * Tagged services are collected by ComplaintHandlerDiscoveryPass and injected
 * into ComplaintHandlerRunner. All handlers run in registration order; one
 * failing handler never blocks the others.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsComplaintHandler {}
