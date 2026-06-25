<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The append-only invariant tripwire for the deploy audit ledger (Block 16, §4.2).
 * {@see \Vortos\Observability\Audit\DeployAuditViewRepositoryInterface} exposes
 * `appendNext()` + reads only — no `update`/`delete` method exists on it, and this
 * test fails the build if any caller in the package issues a raw UPDATE/DELETE SQL
 * statement against the audit table (mirrors the FeatureFlags `WriteBoundaryTest`
 * pattern).
 */
final class AuditAppendOnlyTest extends TestCase
{
    public function test_repository_interface_exposes_no_mutate_or_delete_method(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(\Vortos\Observability\Audit\DeployAuditViewRepositoryInterface::class))->getMethods(),
        );

        foreach ($methods as $method) {
            self::assertDoesNotMatchRegularExpression('/^(update|delete|remove|mutate)/i', $method);
        }
    }

    public function test_no_code_path_issues_raw_update_or_delete_against_the_audit_table(): void
    {
        $packageDir = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($packageDir) as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/Tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (!str_contains($source, 'observability_deploy_audit_log') && !str_contains($source, 'DeployAuditViewRepositoryInterface')) {
                continue;
            }

            // Strip comments/docblocks first — this test is about real SQL strings
            // issued against the ledger, not prose that merely mentions the words.
            $codeOnly = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $codeOnly = (string) preg_replace('#//[^\n]*#', '', $codeOnly);

            if (preg_match('/[\'"]\s*(UPDATE\s+|DELETE\s+FROM\s+)/i', $codeOnly) === 1) {
                $violations[] = str_replace($packageDir . '/', '', $path);
            }
        }

        self::assertSame(
            [],
            $violations,
            "These files issue a raw UPDATE/DELETE against the audit ledger:\n  - " . implode("\n  - ", $violations),
        );
    }

    /** @return iterable<\SplFileInfo> */
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
