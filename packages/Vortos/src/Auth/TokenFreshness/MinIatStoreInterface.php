<?php
declare(strict_types=1);

namespace Vortos\Auth\TokenFreshness;

interface MinIatStoreInterface
{
    public function get(): ?int;

    public function set(int $epoch): void;
}
