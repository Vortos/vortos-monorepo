<?php

declare(strict_types=1);

namespace Vortos\Observability\Driver\Null;

use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorSinkInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Explicit no-op error sink. Discards captured errors; never throws. Useful for dev
 * and tests where shipping errors off-host is undesirable.
 */
#[AsDriver('null')]
final class NullErrorSink implements ErrorSinkInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function capture(CapturedError $error): void
    {
        // Intentionally discarded.
    }

    public function flush(): void
    {
        // Nothing buffered.
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SinkCapability::OffHost->value => false,
            SinkCapability::DiskBuffering->value => false,
            SinkCapability::OtlpNative->value => false,
            SinkCapability::Tls->value => false,
        ]);
    }
}
