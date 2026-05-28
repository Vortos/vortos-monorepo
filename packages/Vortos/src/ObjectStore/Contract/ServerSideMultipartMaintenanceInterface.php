<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\ValueObject\MultipartUpload;

interface ServerSideMultipartMaintenanceInterface
{
    /** @return MultipartUpload[] */
    public function list(?string $prefix = null): array;

    public function abort(string $key, string $uploadId): void;

    public function abortStale(\DateTimeImmutable $olderThan, ?string $prefix = null): int;
}
