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

    public function test_all_returns_every_known_action(): void
    {
        // checkout, setup-php, setup-node, monorepo-split, buildx, qemu, docker-login, build-push,
        // sbom-action, cosign-installer, trivy-action.
        $this->assertCount(11, KnownActionFactory::all());
    }

    public function test_supply_chain_actions_present(): void
    {
        $cosign = KnownActionFactory::cosignInstaller();
        $this->assertSame('sigstore', $cosign->owner);
        $this->assertSame('cosign-installer', $cosign->repo);

        $trivy = KnownActionFactory::trivyImageScan();
        $this->assertSame('aquasecurity', $trivy->owner);
        $this->assertSame('trivy-action', $trivy->repo);
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

    public function test_sbom_action_is_not_downgraded_below_v0_24(): void
    {
        // R7-5: reverses the accidental downgrade to v0.20.7 by an earlier emitter bump.
        $action = KnownActionFactory::sbomAttest();
        $this->assertSame('anchore', $action->owner);
        $this->assertSame('sbom-action', $action->repo);
        $this->assertTrue(
            version_compare(ltrim($action->versionComment, 'v'), '0.24.0', '>='),
            'sbom-action must be pinned at v0.24.0 or newer, got ' . $action->versionComment,
        );
    }

    public function test_docker_actions_are_pinned_to_node24_majors(): void
    {
        // R7-5: docker/* v3 ran on the deprecated Node 20; v4 runs on Node 24.
        foreach ([
            KnownActionFactory::setupBuildx(),
            KnownActionFactory::setupQemu(),
            KnownActionFactory::dockerLogin(),
        ] as $action) {
            $major = (int) ltrim(explode('.', $action->versionComment)[0], 'v');
            $this->assertGreaterThanOrEqual(
                4,
                $major,
                sprintf('%s/%s must be pinned to a Node-24 major (v4+), got %s', $action->owner, $action->repo, $action->versionComment),
            );
        }
    }
}
