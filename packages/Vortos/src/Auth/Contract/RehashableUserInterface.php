<?php
declare(strict_types=1);

namespace Vortos\Auth\Contract;

interface RehashableUserInterface
{
    public function getPasswordHash(): string;
    public function setPasswordHash(string $hash): void;
}
