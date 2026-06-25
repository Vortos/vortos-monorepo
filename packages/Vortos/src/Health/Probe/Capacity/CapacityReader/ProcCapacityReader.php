<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity\CapacityReader;

/**
 * Reads `/proc`, `df`-equivalent disk-space functions and `/proc/cpuinfo` — the only
 * real I/O in the capacity-probe graph. Every parse failure degrades to `null`,
 * never an exception (§6.2 bounded, non-blocking everywhere).
 */
final class ProcCapacityReader implements CapacityReaderInterface
{
    public function diskUsedPct(string $path): ?float
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return (1.0 - ($free / $total)) * 100.0;
    }

    public function memoryUsedPct(): ?float
    {
        if (!is_readable('/proc/meminfo')) {
            return null;
        }

        $contents = @file_get_contents('/proc/meminfo');
        if ($contents === false) {
            return null;
        }

        $total = $this->extractKb($contents, 'MemTotal');
        $available = $this->extractKb($contents, 'MemAvailable');

        if ($total === null || $available === null || $total <= 0.0) {
            return null;
        }

        return (1.0 - ($available / $total)) * 100.0;
    }

    public function cpuLoadPct(): ?float
    {
        $load = @sys_getloadavg();
        if ($load === false) {
            return null;
        }

        $cores = $this->cpuCount();
        if ($cores <= 0) {
            return null;
        }

        return ($load[0] / $cores) * 100.0;
    }

    private function extractKb(string $meminfo, string $key): ?float
    {
        if (preg_match('/^' . preg_quote($key, '/') . ':\s*(\d+)\s*kB/m', $meminfo, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    private function cpuCount(): int
    {
        if (!is_readable('/proc/cpuinfo')) {
            return 0;
        }

        $contents = @file_get_contents('/proc/cpuinfo');
        if ($contents === false) {
            return 0;
        }

        return preg_match_all('/^processor\s*:/m', $contents);
    }
}
