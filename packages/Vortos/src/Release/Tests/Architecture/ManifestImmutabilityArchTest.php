<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class ManifestImmutabilityArchTest extends TestCase
{
    public function test_no_update_or_delete_against_manifest_table_outside_trigger(): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $srcDir = dirname(__DIR__, 2);

        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, '/Tests/') || str_contains($path, '/Resources/migrations/')) {
                continue;
            }

            $code = file_get_contents($path);

            if (
                str_contains($code, 'UPDATE') && str_contains($code, 'release_build_manifests')
                || str_contains($code, 'DELETE') && str_contains($code, 'release_build_manifests')
            ) {
                $relative = str_replace($srcDir . '/', '', $path);
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Production code contains UPDATE/DELETE against release_build_manifests:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    public function test_repository_uses_insert_only(): void
    {
        $repoPath = dirname(__DIR__, 2) . '/ReadModel/DbalManifestRepository.php';
        $this->assertFileExists($repoPath);

        $code = file_get_contents($repoPath);

        $this->assertStringNotContainsString(
            'DO UPDATE SET',
            $this->extractManifestInsert($code),
            'DbalManifestRepository must use INSERT only (no ON CONFLICT DO UPDATE) for the manifests table.',
        );
    }

    private function extractManifestInsert(string $code): string
    {
        if (preg_match('/->insert\(\$this->manifestTable.*?\);/s', $code, $matches)) {
            return $matches[0];
        }

        return '';
    }
}
