<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Driver\Glitchtip\GlitchtipErrorSink;
use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorSinkInterface;
use Vortos\Observability\Sink\ErrorTransportInterface;
use Vortos\Observability\Testing\ErrorSinkConformanceTestCase;

final class GlitchtipErrorSinkConformanceTest extends ErrorSinkConformanceTestCase
{
    protected function createSink(): ErrorSinkInterface
    {
        $spool = new BoundedSpool(
            sys_get_temp_dir() . '/vortos-tck-err-' . bin2hex(random_bytes(6)) . '/e.spool',
            1024 * 1024,
        );

        // A transport that always explodes — proves capture()/flush() never propagate.
        $explodingTransport = new class implements ErrorTransportInterface {
            public function send(string $ingestUrl, CapturedError $error): bool
            {
                throw new \RuntimeException('boom');
            }
        };

        return new GlitchtipErrorSink($spool, $explodingTransport);
    }

    protected function expectedKey(): string
    {
        return 'glitchtip';
    }
}
