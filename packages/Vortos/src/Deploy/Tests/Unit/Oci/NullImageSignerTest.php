<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Oci;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Oci\NullImageSigner;
use Vortos\Deploy\Registry\ImageReference;

final class NullImageSignerTest extends TestCase
{
    public function test_sign_is_noop(): void
    {
        $signer = new NullImageSigner();
        $image = new ImageReference('repo', 'v1', 'sha256:' . str_repeat('ab', 32));

        $signer->sign($image);
        $this->assertTrue(true); // no exception
    }

    public function test_verify_always_returns_true(): void
    {
        $signer = new NullImageSigner();
        $image = new ImageReference('repo', 'v1', 'sha256:' . str_repeat('ab', 32));

        $this->assertTrue($signer->verify($image));
    }
}
