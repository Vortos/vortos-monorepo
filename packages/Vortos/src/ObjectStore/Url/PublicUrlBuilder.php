<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Url;

use Vortos\ObjectStore\Contract\PublicUrlGeneratorInterface;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\PublicUrl;

final class PublicUrlBuilder implements PublicUrlGeneratorInterface
{
    public function __construct(
        private readonly ?string $publicBaseUrl,
        private readonly string $keyPrefix = '',
    ) {}

    public function publicUrl(ObjectKey|string $key): PublicUrl
    {
        if ($this->publicBaseUrl === null || trim($this->publicBaseUrl) === '') {
            throw new ObjectStoreConfigurationException('Public object URLs require bucket.public_base_url to be configured.');
        }

        $key = ObjectKey::from($key);
        $path = trim($this->keyPrefix, '/');
        $path = $path === '' ? $key->value() : $path . '/' . $key->value();

        return new PublicUrl($key, rtrim($this->publicBaseUrl, '/') . '/' . $this->encodePath($path));
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/'))));
    }
}
