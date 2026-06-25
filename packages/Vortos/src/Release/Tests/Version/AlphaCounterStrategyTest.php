<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Version\AlphaCounterStrategy;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\SemverVersion;

final class AlphaCounterStrategyTest extends TestCase
{
    private AlphaCounterStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AlphaCounterStrategy();
    }

    public function test_tag_prefix(): void
    {
        $this->assertSame('v1.0.0-alpha-', $this->strategy->tagPrefix());
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
        yield 'increment from 160' => ['v1.0.0-alpha-160', BumpLevel::Patch, 'v1.0.0-alpha-161'];
        yield 'increment from 100' => ['v1.0.0-alpha-100', BumpLevel::Minor, 'v1.0.0-alpha-101'];
        yield 'bump level irrelevant' => ['v1.0.0-alpha-50', BumpLevel::Major, 'v1.0.0-alpha-51'];
        yield 'none still increments' => ['v1.0.0-alpha-50', BumpLevel::None, 'v1.0.0-alpha-51'];
    }

    public function test_parse_tag_valid(): void
    {
        $version = $this->strategy->parseTag('v1.0.0-alpha-160');
        $this->assertNotNull($version);
        $this->assertSame(1, $version->major);
        $this->assertSame('alpha-160', $version->prerelease);
    }

    public function test_parse_tag_invalid(): void
    {
        $this->assertNull($this->strategy->parseTag('v2.0.0'));
        $this->assertNull($this->strategy->parseTag('not-a-tag'));
    }

    public function test_from_zero_version(): void
    {
        $version = new SemverVersion(0, 0, 0);
        $next = $this->strategy->nextVersion($version, BumpLevel::Patch);
        $this->assertSame('v1.0.0-alpha-1', (string) $next);
    }

    public function test_pinned_vector_alpha_counter(): void
    {
        $v = SemverVersion::parse('v1.0.0-alpha-160');
        $next = $this->strategy->nextVersion($v, BumpLevel::Patch);
        $this->assertSame('v1.0.0-alpha-161', (string) $next);
    }
}
