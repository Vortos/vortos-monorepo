<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\KnownActionFactory;

/**
 * B7 (offline): structural integrity of every pinned action. This does not hit the network — it
 * catches typos, truncation, and duplicate/placeholder SHAs without a token. Existence of each SHA
 * on GitHub is verified separately by the opt-in `pipeline:actions:verify` command.
 */
final class KnownActionPinIntegrityTest extends TestCase
{
    public function test_every_pin_is_well_formed_and_unique(): void
    {
        $actions = KnownActionFactory::all();
        self::assertNotEmpty($actions);

        $seenShas = [];
        foreach ($actions as $action) {
            $ref = $action->toUsesString();

            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                $action->sha,
                sprintf('%s must be pinned to a full 40-hex commit SHA, got "%s".', $ref, $action->sha),
            );
            self::assertNotSame('', $action->owner);
            self::assertNotSame('', $action->repo);
            self::assertNotSame('', $action->versionComment, sprintf('%s must carry a version comment.', $ref));

            // A duplicate SHA across two different actions is almost always a copy-paste error.
            self::assertArrayNotHasKey(
                $action->sha,
                $seenShas,
                sprintf('SHA %s is pinned by both %s and %s.', $action->sha, $seenShas[$action->sha] ?? '', $ref),
            );
            $seenShas[$action->sha] = $ref;
        }
    }
}
