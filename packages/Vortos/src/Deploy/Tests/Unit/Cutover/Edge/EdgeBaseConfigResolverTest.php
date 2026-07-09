<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\Edge;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\EdgeBaseConfigResolver;
use Vortos\Deploy\Cutover\Edge\EdgeConfigFormat;
use Vortos\Deploy\Exception\EdgeBaseConfigException;

final class EdgeBaseConfigResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vortos-edge-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/docker/caddy', 0755, true);
        file_put_contents($this->root . '/docker/caddy/Caddyfile', "example.com {\n  reverse_proxy app-blue:8080\n}\n");
    }

    protected function tearDown(): void
    {
        // Best-effort recursive cleanup of the temp tree.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
    }

    public function testNullPathMeansFeatureOff(): void
    {
        $resolver = new EdgeBaseConfigResolver($this->root);
        self::assertNull($resolver->resolve(null));
        self::assertNull($resolver->resolve(''));
    }

    public function testResolvesRelativePath(): void
    {
        $resolver = new EdgeBaseConfigResolver($this->root);
        $config = $resolver->resolve('docker/caddy/Caddyfile');

        self::assertNotNull($config);
        self::assertSame(EdgeConfigFormat::Caddyfile, $config->format);
        self::assertStringContainsString('app-blue:8080', $config->contents);
    }

    public function testConfiguredButMissingFailsClosed(): void
    {
        $resolver = new EdgeBaseConfigResolver($this->root);
        $this->expectException(EdgeBaseConfigException::class);
        $resolver->resolve('docker/caddy/does-not-exist');
    }

    public function testRejectsPathEscapeViaTraversal(): void
    {
        // A secret file OUTSIDE the project root must never be readable via the base-config path.
        $outside = dirname($this->root) . '/vortos-secret-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'SECRET');

        try {
            $resolver = new EdgeBaseConfigResolver($this->root);
            $this->expectException(EdgeBaseConfigException::class);
            $resolver->resolve('../' . basename($outside));
        } finally {
            @unlink($outside);
        }
    }

    public function testRejectsSymlinkEscape(): void
    {
        $outside = dirname($this->root) . '/vortos-secret-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'SECRET');
        $link = $this->root . '/docker/caddy/link.caddyfile';

        if (!@symlink($outside, $link)) {
            self::markTestSkipped('symlink not supported on this platform');
        }

        try {
            $resolver = new EdgeBaseConfigResolver($this->root);
            $this->expectException(EdgeBaseConfigException::class);
            $resolver->resolve('docker/caddy/link.caddyfile');
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    public function testRejectsOversizeFile(): void
    {
        $resolver = new EdgeBaseConfigResolver($this->root, maxBytes: 8);
        $this->expectException(EdgeBaseConfigException::class);
        $resolver->resolve('docker/caddy/Caddyfile');
    }
}
