<?php

declare(strict_types=1);

namespace Vortos\Logger\Exception;

/**
 * Thrown at container-compile time when config/logging.php produces an
 * inconsistent pipeline (unknown sink reference, compliance floor violation,
 * etc.). Fails the build — never silently degrades.
 */
final class InvalidLoggingConfigException extends \LogicException
{
    public static function unknownSink(string $channel, string $sinkId): self
    {
        return new self(sprintf(
            'Channel "%s" routes to unknown sink "%s". Define it with $config->sink(\'%s\')->... before routing to it.',
            $channel,
            $sinkId,
            $sinkId,
        ));
    }

    public static function duplicateSink(string $sinkId): self
    {
        return new self(sprintf('Sink "%s" is defined more than once.', $sinkId));
    }

    public static function duplicateChannel(string $channel): self
    {
        return new self(sprintf('Channel "%s" is configured more than once.', $channel));
    }

    public static function customSinkMissingHandler(string $sinkId): self
    {
        return new self(sprintf(
            'Sink "%s" uses a custom destination but no customHandlerServiceId was provided.',
            $sinkId,
        ));
    }

    public static function auditRetentionBelowFloor(int $requestedDays, int $floorDays): self
    {
        return new self(sprintf(
            'Audit log retention of %d day(s) is below the compliance floor of %d day(s). '
            . 'Call $config->sink(\'audit\')->acknowledgeComplianceRisk() to override deliberately.',
            $requestedDays,
            $floorDays,
        ));
    }

    public static function fileSinkMissingPath(string $sinkId): self
    {
        return new self(sprintf('File sink "%s" requires a path. Call ->toFile($path).', $sinkId));
    }
}
