<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Url;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Url\PublicUrlBuilder;

final class PublicUrlBuilderTest extends TestCase
{
    /**
     * A public URL must address the key EXACTLY as it was written.
     *
     * The builder used to prepend bucket.key_prefix while nothing else in the store applied it —
     * put() and head() both use the literal key. With OBJECT_STORE_KEY_PREFIX=sqoura/ an object
     * written to "form-banners/x.jpg" was therefore addressed as "sqoura/form-banners/x.jpg" and
     * 404'd. It stayed hidden because avatars and org logos are served through presigned URLs,
     * which use the literal key; form banners were the first feature to need a public URL, and
     * every one of them 404'd in production.
     *
     * The previous version of this test asserted the prefixed URL — it pinned the bug rather than
     * the contract, which is why the suite stayed green while the feature was broken.
     */
    public function test_it_addresses_the_key_exactly_as_written(): void
    {
        $url = (new PublicUrlBuilder('https://cdn.example.test/media/'))
            ->publicUrl('registrations/video final.mp4');

        $this->assertSame('registrations/video final.mp4', $url->key()->value());
        $this->assertSame('https://cdn.example.test/media/registrations/video%20final.mp4', $url->url());
    }

    /**
     * A prefix has to be applied on every path or none. Applying it only when generating a URL is
     * the one combination that cannot work, because the URL then names a location nothing writes to.
     */
    public function test_it_never_injects_a_bucket_prefix_into_the_path(): void
    {
        $url = (new PublicUrlBuilder('https://cdn.example.test'))->publicUrl('form-banners/x.jpg');

        $this->assertSame('https://cdn.example.test/form-banners/x.jpg', $url->url());
    }

    public function test_requires_public_base_url(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        (new PublicUrlBuilder(null))->publicUrl('file.pdf');
    }
}
