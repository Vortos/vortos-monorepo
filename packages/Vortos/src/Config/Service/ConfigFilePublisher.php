<?php

declare(strict_types=1);

namespace Vortos\Config\Service;

use Vortos\Config\Stub\ConfigStub;

final class ConfigFilePublisher
{
    /** @param iterable<ConfigStub> $stubs */
    public function __construct(private readonly iterable $stubs) {}

    /** @return string[] Sorted list of module names that have a registered stub */
    public function available(): array
    {
        $modules = [];

        foreach ($this->stubs as $stub) {
            $modules[] = $stub->module;
        }

        sort($modules);

        return $modules;
    }

    /**
     * Publish config stubs to $projectDir/config/.
     *
     * @param string   $projectDir Absolute path to the project root
     * @param string[] $modules    Module names to publish; empty = all registered
     * @param bool     $force      Overwrite files that already exist
     * @param bool     $dryRun     Preview without writing anything
     */
    public function publish(
        string $projectDir,
        array $modules = [],
        bool $force = false,
        bool $dryRun = false,
    ): ConfigPublishResult {
        $published = [];
        $skipped = [];
        $unknown = [];

        $configDir = $projectDir . DIRECTORY_SEPARATOR . 'config';

        $requested = $modules === [] ? null : array_flip($modules);
        $seen = [];

        foreach ($this->stubs as $stub) {
            if ($requested !== null && !isset($requested[$stub->module])) {
                continue;
            }

            $seen[$stub->module] = true;
            $destination = $configDir . DIRECTORY_SEPARATOR . $stub->module . '.php';

            if (file_exists($destination) && !$force) {
                $skipped[] = 'config/' . $stub->module . '.php';
                continue;
            }

            if (!$dryRun) {
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }

                copy($stub->path, $destination);
            }

            $published[] = 'config/' . $stub->module . '.php';
        }

        foreach (array_keys($requested ?? []) as $module) {
            if (!isset($seen[$module])) {
                $unknown[] = $module;
            }
        }

        return new ConfigPublishResult($published, $skipped, $unknown);
    }
}
