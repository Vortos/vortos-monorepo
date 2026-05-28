<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Url;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Url\PublicUrlBuilder;

final class PublicUrlBuilderTest extends TestCase
{
    public function test_builds_encoded_public_url_with_key_prefix(): void
    {
        $url = (new PublicUrlBuilder('https://cdn.example.test/media/', 'tenant-a'))
            ->publicUrl('registrations/video final.mp4');

        $this->assertSame('registrations/video final.mp4', $url->key()->value());
        $this->assertSame('https://cdn.example.test/media/tenant-a/registrations/video%20final.mp4', $url->url());
    }

    public function test_requires_public_base_url(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        (new PublicUrlBuilder(null))->publicUrl('file.pdf');
    }
}
