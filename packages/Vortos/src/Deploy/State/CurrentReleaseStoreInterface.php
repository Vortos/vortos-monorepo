<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

interface CurrentReleaseStoreInterface
{
    public function recordCurrentRelease(CurrentRelease $release): void;

    public function currentRelease(string $env): ?CurrentRelease;
}
