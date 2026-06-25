<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

final class PackageDiscovery
{
    public function __construct(private readonly string $packagesDir) {}

    /** @return list<PackageInfo> */
    public function discover(): array
    {
        $pattern = $this->packagesDir . '/*/composer.json';
        $files = glob($pattern) ?: [];
        $packages = [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['name']) || !is_string($data['name'])) {
                continue;
            }

            if (!str_starts_with($data['name'], 'vortos/vortos-')) {
                continue;
            }

            $order = $data['extra']['vortos']['order'] ?? 0;

            $packages[] = new PackageInfo(
                name: $data['name'],
                path: \dirname($file),
                order: (int) $order,
            );
        }

        usort($packages, static fn (PackageInfo $a, PackageInfo $b) => $a->order <=> $b->order);

        return $packages;
    }
}
