<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

/**
 * How a browser should treat an object it fetches through a presigned URL.
 *
 * The distinction is a security control, not a convenience. An object store
 * serves whatever Content-Type was recorded at upload, so a bucket that accepts
 * caller-supplied content types can be made to hold `text/html` — and a browser
 * that renders it inline executes it on the storage origin. `Attachment` is the
 * safe default for anything a third party uploaded; `Inline` is for bytes the
 * application itself produced and is willing to have rendered.
 */
enum ContentDispositionType: string
{
    case Attachment = 'attachment';
    case Inline     = 'inline';
}
