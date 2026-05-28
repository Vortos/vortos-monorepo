<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PublicUrl;

interface PublicUrlGeneratorInterface
{
    public function publicUrl(ObjectKey|string $key): PublicUrl;
}
