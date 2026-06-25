<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Registry\ImageReference;

final class FakeImageSigner implements ImageSignerInterface
{
    /** @var list<ImageReference> */
    public array $signed = [];

    /** @var list<ImageReference> */
    public array $verified = [];

    public bool $verifyResult = true;

    public function sign(ImageReference $image): void
    {
        $this->signed[] = $image;
    }

    public function verify(ImageReference $image): bool
    {
        $this->verified[] = $image;

        return $this->verifyResult;
    }
}
