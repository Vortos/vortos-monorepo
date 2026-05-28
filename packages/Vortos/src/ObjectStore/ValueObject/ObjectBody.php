<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class ObjectBody
{
    /** @param resource|string $body */
    private function __construct(
        private mixed $body,
        private readonly ?int $size = null,
    ) {
        if (!is_string($body) && !is_resource($body)) {
            throw new \InvalidArgumentException('Object body must be a string or stream resource.');
        }
    }

    /** @param resource|string|self $body */
    public static function from(mixed $body, ?int $size = null): self
    {
        return $body instanceof self ? $body : new self($body, $size);
    }

    public function isStream(): bool
    {
        return is_resource($this->body);
    }

    /** @return resource|string */
    public function raw(): mixed
    {
        return $this->body;
    }

    public function size(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        return is_string($this->body) ? strlen($this->body) : null;
    }

    public function contents(): string
    {
        if (is_string($this->body)) {
            return $this->body;
        }

        $contents = stream_get_contents($this->body);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read object body stream.');
        }

        return $contents;
    }
}
