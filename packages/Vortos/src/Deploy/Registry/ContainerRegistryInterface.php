<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

use Vortos\OpsKit\Driver\DriverInterface;

interface ContainerRegistryInterface extends DriverInterface
{
    public function push(ImageReference $image): ImageReference;

    public function pull(ImageReference $image): void;

    public function tag(ImageReference $image, string $tag): ImageReference;

    public function digestFor(ImageReference $image): string;
}
