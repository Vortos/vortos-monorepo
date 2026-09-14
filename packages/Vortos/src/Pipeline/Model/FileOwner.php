<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * Numeric owner of a materialised host file. Numeric on purpose: the deploy one-shot runs inside the
 * image, where the host's user names do not exist.
 */
final readonly class FileOwner
{
    public function __construct(
        public int $uid,
        public int $gid,
    ) {
        if ($uid < 0 || $gid < 0) {
            throw new \InvalidArgumentException(sprintf('File owner uid/gid must be non-negative, got %d:%d.', $uid, $gid));
        }
    }

    public static function root(): self
    {
        return new self(0, 0);
    }

    public function toString(): string
    {
        return $this->uid . ':' . $this->gid;
    }
}
