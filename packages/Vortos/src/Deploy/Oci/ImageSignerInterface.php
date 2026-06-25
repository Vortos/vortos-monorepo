<?php

declare(strict_types=1);

namespace Vortos\Deploy\Oci;

use Vortos\Deploy\Registry\ImageReference;

interface ImageSignerInterface
{
    public function sign(ImageReference $image): void;

    public function verify(ImageReference $image): bool;
}
