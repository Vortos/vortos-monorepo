<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class DirectUploadIntent
{
    public function __construct(
        private readonly ObjectKey $temporaryKey,
        private readonly PresignedUploadUrl|PresignedPostPolicy $upload,
        private readonly string $temporaryPrefix = 'tmp',
    ) {
        $prefix = trim($temporaryPrefix, '/');
        if ($prefix === '') {
            throw new \InvalidArgumentException('Temporary upload prefix cannot be empty.');
        }

        if (!str_starts_with($temporaryKey->value(), $prefix . '/')) {
            throw new \InvalidArgumentException(sprintf(
                'Direct upload key must be under the "%s/" temporary prefix.',
                $prefix,
            ));
        }
    }

    public function temporaryKey(): ObjectKey
    {
        return $this->temporaryKey;
    }

    public function upload(): PresignedUploadUrl|PresignedPostPolicy
    {
        return $this->upload;
    }

    public function temporaryPrefix(): string
    {
        return trim($this->temporaryPrefix, '/');
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->upload instanceof PresignedUploadUrl
            ? $this->upload->url()->expiresAt()
            : $this->upload->expiresAt();
    }

    public function constraints(): UploadConstraints
    {
        return $this->upload->constraints();
    }

    public function expired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) >= $this->expiresAt();
    }
}
