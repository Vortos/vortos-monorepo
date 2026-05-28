<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class ByteRange implements \Stringable
{
    public function __construct(
        private readonly int $start,
        private readonly ?int $end = null,
    ) {
        if ($start < 0) {
            throw new \InvalidArgumentException('Byte range start cannot be negative.');
        }

        if ($end !== null && $end < $start) {
            throw new \InvalidArgumentException('Byte range end must be greater than or equal to start.');
        }
    }

    public function start(): int
    {
        return $this->start;
    }

    public function end(): ?int
    {
        return $this->end;
    }

    public function headerValue(): string
    {
        return sprintf('bytes=%d-%s', $this->start, $this->end === null ? '' : (string) $this->end);
    }

    public function __toString(): string
    {
        return $this->headerValue();
    }
}
