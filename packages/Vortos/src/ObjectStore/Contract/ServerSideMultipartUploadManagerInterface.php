<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\ServerSideMultipartUploadOptions;
use Vortos\ObjectStore\ValueObject\StoredObject;

/**
 * Trusted server-side multipart transfer contract.
 *
 * This is for backend-owned transfers such as imports, exports, migrations, and
 * CLI jobs. Public/browser uploads should use direct-to-cloud presigned uploads.
 */
interface ServerSideMultipartUploadManagerInterface
{
    /** @param resource|string|ObjectBody $body */
    public function upload(
        ObjectKey|string $key,
        mixed $body,
        ?PutObjectOptions $options = null,
        ?ServerSideMultipartUploadOptions $transferOptions = null,
    ): StoredObject;
}
