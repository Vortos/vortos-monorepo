<?php

declare(strict_types=1);

namespace Vortos\Deploy\Delivery;

/**
 * One file to ship to the deploy target: its local source, the path relative to the remote deploy
 * dir, the mode to set on the box, and whether its absence is fatal.
 */
final readonly class DeliveryArtifact
{
    public function __construct(
        public string $localPath,
        public string $remoteRelativePath,
        public string $mode = '0644',
        public bool $required = true,
    ) {
        if ($remoteRelativePath === '' || str_starts_with($remoteRelativePath, '/')) {
            throw new \InvalidArgumentException('Remote path must be relative to the deploy dir and non-empty.');
        }

        // Fail closed on traversal — a delivered path must never escape the deploy dir.
        foreach (explode('/', $remoteRelativePath) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException(sprintf('Remote path "%s" must not contain "..".', $remoteRelativePath));
            }
        }
    }

    public function existsLocally(): bool
    {
        return is_file($this->localPath);
    }
}
