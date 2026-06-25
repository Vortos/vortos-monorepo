<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\ConventionalSemverStrategy;
use Vortos\Release\Version\SemverVersion;

final class ConventionalSemverStrategyTest extends TestCase
{
    private ConventionalSemverStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new ConventionalSemverStrategy();
    }

    public function test_tag_prefix(): void
    {
        $this->assertSame('v', $this->strategy->tagPrefix());
    }

    #[DataProvider('nextVersionCases')]
    public function test_next_version(string $current, BumpLevel $bump, string $expected): void
    {
        $version = SemverVersion::parse($current);
        $next = $this->strategy->nextVersion($version, $bump);
        $this->assertSame($expected, (string) $next);
    }

    public static function nextVersionCases(): iterable
    {
        yield 'patch' => ['1.2.3', BumpLevel::Patch, 'v1.2.4'];
        yield 'minor' => ['1.2.3', BumpLevel::Minor, 'v1.3.0'];
        yield 'major' => ['1.2.3', BumpLevel::Major, 'v2.0.0'];
        yield 'none returns same' => ['1.2.3', BumpLevel::None, 'v1.2.3'];
        yield 'clears prerelease' => ['1.0.0-alpha.1', BumpLevel::Patch, 'v1.0.1'];
    }

    public function test_parse_tag_valid(): void
    {
        $v = $this->strategy->parseTag('v2.1.0');
        $this->assertNotNull($v);
        $this->assertSame(2, $v->major);
    }

    public function test_parse_tag_invalid(): void
    {
        $this->assertNull($this->strategy->parseTag('not-a-version'));
    }
}
