<?php
declare(strict_types=1);

namespace Vortos\Auth\TokenFreshness\Storage;

use Vortos\Auth\TokenFreshness\MinIatStoreInterface;

final class InMemoryMinIatStore implements MinIatStoreInterface
{
    private ?int $epoch = null;

    public function get(): ?int
    {
        return $this->epoch;
    }

    public function set(int $epoch): void
    {
        $this->epoch = $epoch;
    }
}
