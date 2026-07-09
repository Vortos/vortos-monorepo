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

    public function testDecodePreservesEmptyObjectsSoBootHashMatchesRecordedHash(): void
    {
        // The recorded hash is of a merged config whose empty-object handler (encode-gzip) is a
        // \stdClass {}. The boot file on disk encodes it as {}. Re-reading the boot file via
        // MergeOutcome::decode must yield the SAME canonical hash — a json_decode(true) would turn {}
        // into [] and canonicalize differently, causing a phantom "boot file does not match" drift.
        $config = [
            'apps' => ['http' => ['servers' => ['app' => [
                'routes' => [['handle' => [
                    ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-green:8080']]],
                    ['handler' => 'encode', 'encodings' => ['gzip' => new \stdClass()]],
                ]]],
                'empties' => [], // a genuine empty array must stay []
            ]]]],
        ];
        $recordedHash = hash('sha256', MergeOutcome::canonicalize($config));

        $bootJson = json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
        $bootHash = hash('sha256', MergeOutcome::canonicalize(MergeOutcome::decode($bootJson)));

        // Sanity: the empty object is on disk as an object, not an array.
        self::assertMatchesRegularExpression('/"gzip":\s*\{\}/', $bootJson);
        self::assertStringNotContainsString('"gzip":[]', $bootJson);
        self::assertSame($recordedHash, $bootHash);
    }
}
