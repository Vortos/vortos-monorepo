<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The write-boundary tripwire (PHASE B / Block 7).
 *
 * Every feature-flag mutation MUST go through
 * {@see \Vortos\FeatureFlags\Application\FlagWriteService} so it is recorded in the
 * ledger / audit history. A direct `FlagStorageInterface::save()` / `delete()` call
 * elsewhere works at runtime but silently skips the audit trail — the failure is
 * invisible until an incident. This test makes that invariant self-enforcing: it fails
 * the build the moment any other class (a future management API, a new CLI command, a
 * hotfix) writes to flag storage directly.
 *
 * Exempt: the write service itself, the storage adapters (decorator delegation /
 * the SQL implementation), and tests.
 */
final class WriteBoundaryTest extends TestCase
{
    public function test_only_the_write_service_calls_flag_storage_mutators(): void
    {
        $packageDir = dirname(__DIR__, 2);

        $exemptPrefixes = [
            $packageDir . '/Application/FlagWriteService.php',
            // FlagPromotionService calls FlagEnvironmentStateStorageInterface::save(), not
            // FlagStorageInterface::save() — it only reads flag definitions for audit context.
            $packageDir . '/Application/FlagPromotionService.php',
            // GuardrailWatcherService reads FlagStorageInterface::findByName() to inspect a
            // flag's ramp schedule; its ->save() calls target GuardrailPolicyStorageInterface.
            // Every flag mutation it performs is routed through FlagWriteService::disable()/schedule().
            $packageDir . '/Guardrail/GuardrailWatcherService.php',
            $packageDir . '/Storage/',
            // Block 16: circuit-breaker is a storage decorator (like RedisCachingStorage) — it
            // delegates save/delete write-through to the inner storage, never initiates mutations.
            $packageDir . '/Delivery/CircuitBreakerFlagStorage.php',
            $packageDir . '/Tests/',
        ];

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($this->phpFiles($packageDir) as $file) {
            $path = $file->getPathname();

            foreach ($exemptPrefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }

            $source = (string) file_get_contents($path);

            // Only flag-storage callers are in scope (segment storage is allowed to be
            // called directly until its own write boundary lands). A file is a flag-storage
            // caller iff it references FlagStorageInterface.
            if (!str_contains($source, 'FlagStorageInterface')) {
                continue;
            }

            if (preg_match('/->\s*save\s*\(/', $source) || preg_match('/->\s*delete\s*\(/', $source)) {
                $violations[] = str_replace($packageDir . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These classes write to flag storage directly — route them through FlagWriteService:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private function phpFiles(string $dir): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
