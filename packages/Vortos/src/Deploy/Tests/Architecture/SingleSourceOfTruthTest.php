<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class SingleSourceOfTruthTest extends TestCase
{
    public function test_edge_config_generator_does_not_define_conflicting_upstream(): void
    {
        $generatorFile = dirname(__DIR__, 2) . '/Cutover/EdgeConfigGenerator.php';
        if (!file_exists($generatorFile)) {
            $this->markTestSkipped('EdgeConfigGenerator not yet created.');
        }

        $code = (string) file_get_contents($generatorFile);

        $this->assertStringNotContainsString(
            'autosave',
            strtolower($code),
            'EdgeConfigGenerator must not reference autosave — single source of truth.',
        );
    }

    public function test_no_static_caddyfile_in_stubs(): void
    {
        $stubDir = dirname(__DIR__, 2) . '/Resources/stubs/edge';
        if (!is_dir($stubDir)) {
            $this->markTestSkipped('Edge stubs directory not yet created.');
        }

        $files = glob($stubDir . '/Caddyfile*');
        $this->assertSame(
            [],
            $files ?: [],
            'No static Caddyfile should exist in stubs — managed JSON fragment is the single source of truth.',
        );
    }
}
