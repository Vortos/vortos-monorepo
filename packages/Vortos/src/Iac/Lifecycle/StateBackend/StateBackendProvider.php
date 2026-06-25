<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\StateBackend;

enum StateBackendProvider: string
{
    case S3Dynamodb = 's3';
    case Gcs = 'gcs';
    case Local = 'local';

    public function isRemote(): bool
    {
        return $this !== self::Local;
    }

    public function supportsLocking(): bool
    {
        return $this !== self::Local;
    }
}
