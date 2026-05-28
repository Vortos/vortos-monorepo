<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

enum UploadMethod: string
{
    case SignedPut = 'signed_put';
    case PostPolicy = 'post_policy';
}
