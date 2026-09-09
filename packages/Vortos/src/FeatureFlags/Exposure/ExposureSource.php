<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

/**
 * Where an exposure was observed. Carried on {@see ExposureRecord} so downstream
 * analytics can tell a browser-reported exposure apart from one the server produced
 * while gating a request — without which the two are indistinguishable and a
 * server-only flag looks like it has no traffic at all.
 */
enum ExposureSource: string
{
    /** Reported by a client SDK through the public exposure endpoint. */
    case Sdk = 'sdk';

    /** Produced server-side by an actual flag evaluation on the request path. */
    case Server = 'server';
}
