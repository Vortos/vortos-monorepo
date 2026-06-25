<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\KnownActionFactory;

final class KnownActionFactoryTest extends TestCase
{
    public function test_all_shas_are_40_char_lowercase_hex(): void
    {
        foreach (KnownActionFactory::all() as $action) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                $action->sha,
                sprintf('%s/%s has invalid SHA: %s', $action->owner, $action->repo, $action->sha),
            );
        }
    }

    public function test_all_version_comments_are_non_empty(): void
    {
        foreach (KnownActionFactory::all() as $action) {
            $this->assertNotSame('', $action->versionComment);
        }
    }

    public function test_all_returns_nine_actions(): void
    {
        $this->assertCount(9, KnownActionFactory::all());
    }

    public function test_checkout_owner_and_repo(): void
    {
        $action = KnownActionFactory::checkout();
        $this->assertSame('actions', $action->owner);
        $this->assertSame('checkout', $action->repo);
    }

    public function test_setup_php_owner_and_repo(): void
    {
        $action = KnownActionFactory::setupPhp();
        $this->assertSame('shivammathur', $action->owner);
        $this->assertSame('setup-php', $action->repo);
    }

    public function test_setup_node_owner_and_repo(): void
    {
        $action = KnownActionFactory::setupNode();
        $this->assertSame('actions', $action->owner);
        $this->assertSame('setup-node', $action->repo);
    }

    public function test_monorepo_split_owner_and_repo(): void
    {
        $action = KnownActionFactory::monorepoSplit();
        $this->assertSame('danharrin', $action->owner);
        $this->assertSame('monorepo-split-github-action', $action->repo);
    }
}
