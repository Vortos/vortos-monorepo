<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Url;

use Vortos\ObjectStore\Contract\PublicUrlGeneratorInterface;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PublicUrl;

/**
 * Builds a public URL for a key EXACTLY as the caller wrote it.
 *
 * WHY THERE IS NO KEY PREFIX HERE
 *
 * This used to prepend bucket.key_prefix. Nothing else in the store applied it: put() and head()
 * both use the literal key, so with OBJECT_STORE_KEY_PREFIX=sqoura/ an object written to
 * "form-banners/x.jpg" was addressed as "sqoura/form-banners/x.jpg" here — and 404'd.
 *
 * The mismatch stayed hidden because avatars and organisation logos are served through short-lived
 * PRESIGNED URLs, which go to the store with the literal key and always resolve. Form banners were
 * the first feature to use a public URL, and every one of them 404'd in production.
 *
 * A prefix has to be applied on every path or none. Applying it only when generating a URL is the
 * one combination that cannot work, because the URL then describes a location nothing writes to.
 * OBJECT_STORE_TEMPORARY_KEY_PREFIX takes the other coherent option — callers build it into the key
 * itself — which is why temporary objects really do live under their prefix.
 */
final class PublicUrlBuilder implements PublicUrlGeneratorInterface
{
    public function __construct(
        private readonly ?string $publicBaseUrl,
    ) {}

    public function publicUrl(ObjectKey|string $key): PublicUrl
    {
        if ($this->publicBaseUrl === null || trim($this->publicBaseUrl) === '') {
            throw new ObjectStoreConfigurationException('Public object URLs require bucket.public_base_url to be configured.');
        }

        $key = ObjectKey::from($key);

        return new PublicUrl($key, rtrim($this->publicBaseUrl, '/') . '/' . $this->encodePath($key->value()));
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/'))));
    }
}
