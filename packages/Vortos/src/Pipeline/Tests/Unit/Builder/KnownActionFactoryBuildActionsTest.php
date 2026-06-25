<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\KnownActionFactory;

final class KnownActionFactoryBuildActionsTest extends TestCase
{
    public function test_setup_buildx_is_sha_pinned(): void
    {
        $action = KnownActionFactory::setupBuildx();
        $this->assertSame('docker', $action->owner);
        $this->assertSame('setup-buildx-action', $action->repo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $action->sha);
    }

    public function test_setup_qemu_is_sha_pinned(): void
    {
        $action = KnownActionFactory::setupQemu();
        $this->assertSame('docker', $action->owner);
        $this->assertSame('setup-qemu-action', $action->repo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $action->sha);
    }

    public function test_docker_login_is_sha_pinned(): void
    {
        $action = KnownActionFactory::dockerLogin();
        $this->assertSame('docker', $action->owner);
        $this->assertSame('login-action', $action->repo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $action->sha);
    }

    public function test_build_push_is_sha_pinned(): void
    {
        $action = KnownActionFactory::buildPush();
        $this->assertSame('docker', $action->owner);
        $this->assertSame('build-push-action', $action->repo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $action->sha);
    }

    public function test_sbom_attest_is_sha_pinned(): void
    {
        $action = KnownActionFactory::sbomAttest();
        $this->assertSame('anchore', $action->owner);
        $this->assertSame('sbom-action', $action->repo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $action->sha);
    }

    public function test_all_includes_new_actions(): void
    {
        $all = KnownActionFactory::all();
        $repos = array_map(fn ($a) => $a->repo, $all);

        $this->assertContains('setup-buildx-action', $repos);
        $this->assertContains('setup-qemu-action', $repos);
        $this->assertContains('login-action', $repos);
        $this->assertContains('build-push-action', $repos);
        $this->assertContains('sbom-action', $repos);
    }

    public function test_all_actions_are_40_hex_sha(): void
    {
        foreach (KnownActionFactory::all() as $action) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                $action->sha,
                sprintf('Action %s/%s is not SHA-pinned', $action->owner, $action->repo),
            );
        }
    }
}
