<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

/**
 * The OTLP wire protocol an endpoint speaks.
 *
 * OTLP is vendor-neutral (§12.4 agnostic note): the protocol is a transport detail
 * of the endpoint, never a backend identity. The collector receives both on the app
 * host and exports over whichever the selected backend expects.
 */
enum OtlpProtocol: string
{
    /** gRPC, conventionally on port 4317. */
    case Grpc = 'grpc';

    /** HTTP/protobuf, conventionally on port 4318. */
    case HttpProtobuf = 'http/protobuf';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Grpc => 4317,
            self::HttpProtobuf => 4318,
        };
    }
}
