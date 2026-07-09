<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\Edge;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\AppProxyLocation;
use Vortos\Deploy\Cutover\Edge\MergeAction;
use Vortos\Deploy\Cutover\Edge\MergeOutcome;

final class MergeOutcomeTest extends TestCase
{
    public function testHashIsStableAcrossKeyOrdering(): void
    {
        $location = new AppProxyLocation(['apps', 'http'], 'app', 'example.com');

        $a = new MergeOutcome(['b' => 1, 'a' => ['y' => 2, 'x' => 3]], MergeAction::Patched, $location);
        $b = new MergeOutcome(['a' => ['x' => 3, 'y' => 2], 'b' => 1], MergeAction::Patched, $location);

        self::assertSame($a->sha256, $b->sha256);
    }

    public function testHashIsSensitiveToListOrder(): void
    {
        $location = new AppProxyLocation(['apps'], 'app', 'example.com');

        $a = new MergeOutcome(['u' => [['dial' => 'app-blue:8080'], ['dial' => 'app-green:8080']]], MergeAction::Patched, $location);
        $b = new MergeOutcome(['u' => [['dial' => 'app-green:8080'], ['dial' => 'app-blue:8080']]], MergeAction::Patched, $location);

        self::assertNotSame($a->sha256, $b->sha256);
    }

    public function testCanonicalizeMatchesConstructorHash(): void
    {
        $location = new AppProxyLocation(['apps'], 'app', 'example.com');
        $config = ['b' => 1, 'a' => 2];

        $outcome = new MergeOutcome($config, MergeAction::Inserted, $location);

        self::assertSame(hash('sha256', MergeOutcome::canonicalize($config)), $outcome->sha256);
    }
}
