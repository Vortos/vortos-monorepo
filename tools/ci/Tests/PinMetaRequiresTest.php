<?php

declare(strict_types=1);

namespace Vortos\Tools\Ci\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers the release step that keeps framework service discovery and the split packages on
 * one version. This runs once per release and only in CI, so a defect here would otherwise
 * surface as a broken container in somebody's deploy rather than a failing build.
 */
final class PinMetaRequiresTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../pin-meta-requires.php';

    private string $manifest;

    protected function setUp(): void
    {
        $this->manifest = tempnam(sys_get_temp_dir(), 'meta') . '.json';
        file_put_contents($this->manifest, json_encode([
            'name'    => 'vortos/vortos-framework',
            'require' => [
                'php'                        => '>=8.2',
                'ext-json'                   => '*',
                'vortos/vortos-foundation'   => '^1.0',
                'vortos/vortos-authorization' => '^1.0@alpha',
                'psr/log'                    => '^3.0',
            ],
        ], JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
    }

    public function test_pins_every_sibling_to_the_released_version(): void
    {
        self::assertSame(0, $this->runScript('1.0.0-alpha-274'));

        $require = $this->requireBlock();

        self::assertSame('1.0.0-alpha-274', $require['vortos/vortos-foundation']);
        self::assertSame('1.0.0-alpha-274', $require['vortos/vortos-authorization']);
    }

    /** Pinning php or third-party constraints would make the package uninstallable. */
    public function test_leaves_non_sibling_requires_untouched(): void
    {
        self::assertSame(0, $this->runScript('1.0.0-alpha-274'));

        $require = $this->requireBlock();

        self::assertSame('>=8.2', $require['php']);
        self::assertSame('*', $require['ext-json']);
        self::assertSame('^3.0', $require['psr/log']);
    }

    public function test_accepts_a_tag_and_strips_its_prefix(): void
    {
        self::assertSame(0, $this->runScript('v1.0.0-alpha-274'));

        self::assertSame('1.0.0-alpha-274', $this->requireBlock()['vortos/vortos-foundation']);
    }

    /**
     * A branch build must not pin siblings to something like "main" — that resolves for
     * nobody and would ship a meta-package that cannot be installed at all.
     */
    public function test_refuses_a_version_that_is_not_a_release(): void
    {
        self::assertSame(2, $this->runScript('main'));

        self::assertSame('^1.0', $this->requireBlock()['vortos/vortos-foundation'], 'manifest must be left untouched');
    }

    public function test_refuses_a_manifest_with_no_siblings(): void
    {
        file_put_contents($this->manifest, json_encode(['require' => ['php' => '>=8.2']]));

        self::assertSame(1, $this->runScript('1.0.0-alpha-274'));
    }

    private function runScript(string $version): int
    {
        $code = 0;
        exec(sprintf('php %s %s %s 2>/dev/null', escapeshellarg(self::SCRIPT), escapeshellarg($version), escapeshellarg($this->manifest)), $_, $code);

        return $code;
    }

    /** @return array<string, string> */
    private function requireBlock(): array
    {
        return json_decode((string) file_get_contents($this->manifest), true, 512, JSON_THROW_ON_ERROR)['require'];
    }
}
