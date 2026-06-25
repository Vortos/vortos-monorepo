<?php
declare(strict_types=1);

namespace Vortos\Auth\Contract;

interface RehashableUserPersisterInterface
{
    public function save(RehashableUserInterface $user): void;
}
