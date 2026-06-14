<?php

declare(strict_types=1);

namespace Vortos\Logger\Config;

/**
 * Where a sink ultimately writes records.
 *
 * Custom destinations (OTLP collectors, Kafka topics, etc.) are not modeled
 * here — register a custom Monolog handler via the `vortos.logger.handler`
 * DI tag and reference it with SinkBuilder::customHandler().
 */
enum SinkDestination
{
    case File;
    case Stream;
    case Syslog;
    case Null;
    case Custom;
}
