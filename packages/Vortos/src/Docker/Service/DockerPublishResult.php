<?php

declare(strict_types=1);

namespace Vortos\Docker\Service;

final class DockerPublishResult
{
    /**
     * @param string[]                                          $copied
     * @param string[]                                          $skipped
     * @param string[]                                          $backedUp
     * @param array<string, array{current: int, new: int}>      $diverged files that already differ from
     *        the stub, with their current and would-be line counts. Reported separately from `copied`
     *        because overwriting one silently reverts work somebody did on purpose — see
     *        DockerFilePublisher::publish().
     */
    public function __construct(
        public readonly array $copied = [],
        public readonly array $skipped = [],
        public readonly array $backedUp = [],
        public readonly array $diverged = [],
    ) {}

    /** Whether any file would lose local changes if this publish were applied. */
    public function hasDiverged(): bool
    {
        return $this->diverged !== [];
    }
}
