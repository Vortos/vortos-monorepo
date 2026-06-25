<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

class KubeApiException extends \RuntimeException
{
    public static function applyFailed(string $kind, string $name, string $reason): self
    {
        return new self(sprintf('Failed to apply %s/%s: %s', $kind, $name, $reason));
    }

    public static function jobFailed(string $name, string $reason): self
    {
        return new self(sprintf('Job "%s" failed: %s', $name, $reason));
    }

    public static function scaleFailed(string $kind, string $name, string $reason): self
    {
        return new self(sprintf('Failed to scale %s/%s: %s', $kind, $name, $reason));
    }
}
