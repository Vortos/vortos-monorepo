<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\Edge;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\EdgeBaseConfig;
use Vortos\Deploy\Cutover\Edge\EdgeConfigFormat;

final class EdgeBaseConfigTest extends TestCase
{
    public function testHashesContentAndReportsLength(): void
    {
        $config = new EdgeBaseConfig('/etc/caddy/Caddyfile', "example.com {\n}\n", EdgeConfigFormat::Caddyfile);

        self::assertSame(hash('sha256', "example.com {\n}\n"), $config->sha256);
        self::assertSame(16, $config->byteLength());
    }

    public function testToStringIsSecretFree(): void
    {
        $body = "example.com {\n  basicauth {\n    admin JDJhJDE0superSecretHash\n  }\n}\n";
        $config = new EdgeBaseConfig('/etc/caddy/Caddyfile', $body, EdgeConfigFormat::Caddyfile);

        $descriptor = (string) $config;

        self::assertStringNotContainsString('superSecretHash', $descriptor);
        self::assertStringNotContainsString('basicauth', $descriptor);
        self::assertStringContainsString('/etc/caddy/Caddyfile', $descriptor);
        self::assertStringContainsString('caddyfile', $descriptor);
        self::assertStringContainsString(substr($config->sha256, 0, 12), $descriptor);
    }

    public function testRejectsEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EdgeBaseConfig('', 'x', EdgeConfigFormat::Caddyfile);
    }

    public function testRejectsEmptyContents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EdgeBaseConfig('/etc/caddy/Caddyfile', '', EdgeConfigFormat::Caddyfile);
    }

    public function testFormatDetection(): void
    {
        self::assertSame(EdgeConfigFormat::Json, EdgeConfigFormat::fromPath('/x/caddy.json'));
        self::assertSame(EdgeConfigFormat::Caddyfile, EdgeConfigFormat::fromPath('/x/Caddyfile'));
        self::assertSame(EdgeConfigFormat::Caddyfile, EdgeConfigFormat::fromPath('/x/edge.caddy'));
        self::assertSame(EdgeConfigFormat::Caddyfile, EdgeConfigFormat::fromPath('/x/edge.caddyfile'));
        self::assertTrue(EdgeConfigFormat::Caddyfile->requiresAdapt());
        self::assertFalse(EdgeConfigFormat::Json->requiresAdapt());
    }
}
