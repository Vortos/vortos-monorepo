<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\InvalidVersionException;
use Vortos\Release\Version\SemverVersion;

final class SemverVersionTest extends TestCase
{
    // ── Parsing ──

    #[DataProvider('validVersions')]
    public function test_parse_valid(string $input, int $major, int $minor, int $patch, ?string $pre, ?string $build): void
    {
        $v = SemverVersion::parse($input);
        $this->assertSame($major, $v->major);
        $this->assertSame($minor, $v->minor);
        $this->assertSame($patch, $v->patch);
        $this->assertSame($pre, $v->prerelease);
        $this->assertSame($build, $v->buildMetadata);
    }

    public static function validVersions(): iterable
    {
        yield 'simple' => ['1.2.3', 1, 2, 3, null, null];
        yield 'with v prefix' => ['v1.2.3', 1, 2, 3, null, null];
        yield 'zero version' => ['0.0.0', 0, 0, 0, null, null];
        yield 'prerelease' => ['1.0.0-alpha', 1, 0, 0, 'alpha', null];
        yield 'prerelease dotted' => ['1.0.0-alpha.1', 1, 0, 0, 'alpha.1', null];
        yield 'alpha counter' => ['v1.0.0-alpha-100', 1, 0, 0, 'alpha-100', null];
        yield 'build metadata' => ['1.0.0+build.42', 1, 0, 0, null, 'build.42'];
        yield 'pre + build' => ['1.0.0-rc.1+sha.abc', 1, 0, 0, 'rc.1', 'sha.abc'];
        yield 'large numbers' => ['999.999.999', 999, 999, 999, null, null];
    }

    #[DataProvider('invalidVersions')]
    public function test_parse_invalid(string $input): void
    {
        $this->expectException(InvalidVersionException::class);
        SemverVersion::parse($input);
    }

    public static function invalidVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['not-a-version'];
        yield 'partial' => ['1.2'];
        yield 'negative' => ['-1.0.0'];
        yield 'leading zeros' => ['01.0.0'];
    }

    // ── Comparison ──

    #[DataProvider('comparisonPairs')]
    public function test_compare(string $a, string $b, int $expected): void
    {
        $va = SemverVersion::parse($a);
        $vb = SemverVersion::parse($b);
        $this->assertSame($expected, $va->compare($vb) <=> 0);
    }

    public static function comparisonPairs(): iterable
    {
        yield 'equal' => ['1.0.0', '1.0.0', 0];
        yield 'major' => ['2.0.0', '1.0.0', 1];
        yield 'minor' => ['1.1.0', '1.0.0', 1];
        yield 'patch' => ['1.0.1', '1.0.0', 1];
        yield 'pre < release' => ['1.0.0-alpha', '1.0.0', -1];
        yield 'alpha < beta' => ['1.0.0-alpha', '1.0.0-beta', -1];
        yield 'alpha-9 < alpha-10 numeric' => ['1.0.0-alpha.9', '1.0.0-alpha.10', -1];
        yield 'rc < release' => ['1.0.0-rc.1', '1.0.0', -1];
        yield 'alpha.1 < alpha.2' => ['1.0.0-alpha.1', '1.0.0-alpha.2', -1];
        yield 'v prefix ignored' => ['v1.0.0', '1.0.0', 0];
    }

    // ── Bump ──

    #[DataProvider('bumpCases')]
    public function test_with_bump(string $input, BumpLevel $level, string $expected): void
    {
        $v = SemverVersion::parse($input);
        $bumped = $v->withBump($level);
        $this->assertSame($expected, (string) $bumped);
    }

    public static function bumpCases(): iterable
    {
        yield 'patch' => ['1.2.3', BumpLevel::Patch, 'v1.2.4'];
        yield 'minor resets patch' => ['1.2.3', BumpLevel::Minor, 'v1.3.0'];
        yield 'major resets minor+patch' => ['1.2.3', BumpLevel::Major, 'v2.0.0'];
        yield 'none is identity' => ['1.2.3', BumpLevel::None, 'v1.2.3'];
        yield 'from zero' => ['0.0.0', BumpLevel::Patch, 'v0.0.1'];
    }

    // ── Prerelease flags ──

    public function test_is_prerelease(): void
    {
        $this->assertTrue(SemverVersion::parse('1.0.0-alpha')->isPrerelease());
        $this->assertFalse(SemverVersion::parse('1.0.0')->isPrerelease());
    }

    public function test_is_stable(): void
    {
        $this->assertTrue(SemverVersion::parse('1.0.0')->isStable());
        $this->assertFalse(SemverVersion::parse('0.9.0')->isStable());
        $this->assertFalse(SemverVersion::parse('1.0.0-alpha')->isStable());
    }

    // ── toString ──

    public function test_to_string(): void
    {
        $v = new SemverVersion(1, 2, 3, 'rc.1', 'build.7');
        $this->assertSame('v1.2.3-rc.1+build.7', (string) $v);
    }

    // ── toArray ──

    public function test_to_array(): void
    {
        $v = new SemverVersion(1, 0, 0, 'alpha-160');
        $arr = $v->toArray();
        $this->assertSame(1, $arr['major']);
        $this->assertSame('alpha-160', $arr['prerelease']);
    }

    // ── Negative construction ──

    public function test_negative_component_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SemverVersion(-1, 0, 0);
    }

    // ── withPrerelease ──

    public function test_with_prerelease(): void
    {
        $v = SemverVersion::parse('1.2.3');
        $pre = $v->withPrerelease('beta.1');
        $this->assertSame('v1.2.3-beta.1', (string) $pre);

        $cleared = $pre->withPrerelease(null);
        $this->assertSame('v1.2.3', (string) $cleared);
    }

    // ── Equals / greaterThan ──

    public function test_equals(): void
    {
        $a = SemverVersion::parse('1.0.0');
        $b = SemverVersion::parse('v1.0.0');
        $this->assertTrue($a->equals($b));
    }

    public function test_greater_than(): void
    {
        $a = SemverVersion::parse('2.0.0');
        $b = SemverVersion::parse('1.9.9');
        $this->assertTrue($a->greaterThan($b));
        $this->assertFalse($b->greaterThan($a));
    }
}
