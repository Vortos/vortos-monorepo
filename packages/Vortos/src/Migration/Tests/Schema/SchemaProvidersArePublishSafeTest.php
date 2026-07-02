<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;

/**
 * Publish-safety ratchet: every shipped module schema provider must be introspectable against a
 * FRESH (empty) Schema without throwing.
 *
 * vortos:migrate:publish runs define()/ownership() against an empty Schema. An alter-style
 * provider that calls $schema->getTable() unconditionally throws there — and because the publish
 * run is a single pass, that one failure historically aborted the ENTIRE run, so unrelated
 * packages' migrations (e.g. the release manifests deploy:doctor needs) never published (P1-5).
 *
 * Alter-style providers must guard with $schema->hasTable() (the framework-wide pattern). This
 * test enforces it across every module so the regression cannot return.
 */
final class SchemaProvidersArePublishSafeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function providerFiles(): iterable
    {
        $srcRoot = \dirname(__DIR__, 4);
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_contains($path, '/Resources/migrations/') || !str_ends_with($path, '.php')) {
                continue;
            }
            yield $file->getFilename() => [$path];
        }
    }

    #[DataProvider('providerFiles')]
    public function test_provider_is_publish_safe_against_a_fresh_schema(string $path): void
    {
        /** @var mixed $provider */
        $provider = require $path;

        if (!$provider instanceof ModuleSchemaProviderInterface) {
            // Not a schema provider (e.g. a plain migration class file) — nothing to assert.
            $this->addToAssertionCount(1);
            return;
        }

        // ownership() runs define() against a fresh Schema — exactly what publish does. It must
        // not throw regardless of whether the provider creates or alters tables.
        $ownership = $provider->ownership();

        self::assertNotNull($ownership, basename($path) . ' must yield ownership on a fresh schema.');
    }
}
