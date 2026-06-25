<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Integration\Pipeline\SupplyChainActions;

final class PipelineSupplyChainActionsTest extends TestCase
{
    public function test_all_actions_are_sha_pinned(): void
    {
        foreach (SupplyChainActions::all() as $action) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                $action->sha,
                sprintf('Action %s/%s must be SHA-pinned.', $action->owner, $action->repo),
            );
        }
    }

    public function test_cosign_installer_is_pinned(): void
    {
        $action = SupplyChainActions::cosignInstaller();
        self::assertSame('sigstore', $action->owner);
        self::assertSame('cosign-installer', $action->repo);
    }

    public function test_trivy_action_is_pinned(): void
    {
        $action = SupplyChainActions::trivyAction();
        self::assertSame('aquasecurity', $action->owner);
        self::assertSame('trivy-action', $action->repo);
    }
}
