<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class PresignedUploadUrl
{
    public function __construct(
        private readonly ObjectKey $key,
        private readonly PresignedUrl $url,
        private readonly UploadConstraints $constraints,
    ) {
        if ($url->method() !== HttpMethod::Put) {
            throw new \InvalidArgumentException('Presigned upload URL must use PUT.');
        }
    }

    public function key(): ObjectKey
    {
        return $this->key;
    }

    public function url(): PresignedUrl
    {
        return $this->url;
    }

    public function constraints(): UploadConstraints
    {
        return $this->constraints;
    }

    /** @return array<string, string> */
    public function requiredHeaders(): array
    {
        return array_replace($this->constraints->requiredHeaders(), $this->url->headers());
    }
}
