<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Vortos\FeatureFlags\Segment;

interface SegmentStorageInterface
{
    /** @return Segment[] */
    public function findAll(): array;

    public function findByName(string $name): ?Segment;

    public function save(Segment $segment): void;

    public function delete(string $name): void;
}
