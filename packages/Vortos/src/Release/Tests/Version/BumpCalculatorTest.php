<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Version\BumpCalculator;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\ConventionalCommit;

final class BumpCalculatorTest extends TestCase
{
    private BumpCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BumpCalculator();
    }

    #[DataProvider('bumpCases')]
    public function test_calculate(array $types, bool $preStable, BumpLevel $expected): void
    {
        $commits = array_map(
            fn (array $spec) => new ConventionalCommit(
                type: $spec[0],
                scope: null,
                breaking: $spec[1],
                description: 'test',
                body: '',
                footers: [],
                sha: 'abc',
            ),
            $types,
        );

        $this->assertSame($expected, $this->calculator->calculate($commits, $preStable));
    }

    public static function bumpCases(): iterable
    {
        yield 'empty' => [[], false, BumpLevel::None];
        yield 'single feat' => [[['feat', false]], false, BumpLevel::Minor];
        yield 'single fix' => [[['fix', false]], false, BumpLevel::Patch];
        yield 'breaking' => [[['feat', true]], false, BumpLevel::Major];
        yield 'chore only' => [[['chore', false]], false, BumpLevel::None];
        yield 'docs only' => [[['docs', false]], false, BumpLevel::None];
        yield 'mixed: feat + fix = minor (highest wins)' => [[['fix', false], ['feat', false]], false, BumpLevel::Minor];
        yield 'mixed: breaking + feat = major' => [[['feat', false], ['feat', true]], false, BumpLevel::Major];
        yield 'mixed: chore + fix = patch' => [[['chore', false], ['fix', false]], false, BumpLevel::Patch];

        // Pre-stable demotion
        yield 'pre-stable: breaking → minor' => [[['feat', true]], true, BumpLevel::Minor];
        yield 'pre-stable: feat → patch' => [[['feat', false]], true, BumpLevel::Patch];
        yield 'pre-stable: fix → patch' => [[['fix', false]], true, BumpLevel::Patch];
        yield 'pre-stable: chore → none' => [[['chore', false]], true, BumpLevel::None];
    }
}
