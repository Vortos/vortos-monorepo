<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Action\AuditActionProviderInterface;
use Vortos\Audit\Action\AuditActionRegistry;
use Vortos\Audit\Action\RegisteredAction;
use Vortos\Audit\Enum\Sensitivity;

final class AuditActionRegistryTest extends TestCase
{
    public function test_aggregates_actions_from_multiple_providers(): void
    {
        $registry = new AuditActionRegistry([
            $this->provider([new RegisteredAction('member.invited', 'Member invited')]),
            $this->provider([new RegisteredAction('flag.published', 'Flag published', Sensitivity::High)]),
        ]);

        self::assertTrue($registry->has('member.invited'));
        self::assertTrue($registry->has('flag.published'));
        self::assertSame(Sensitivity::High, $registry->get('flag.published')?->sensitivity);
        self::assertCount(2, $registry->all());
    }

    public function test_duplicate_action_key_is_rejected(): void
    {
        $this->expectException(\LogicException::class);

        new AuditActionRegistry([
            $this->provider([new RegisteredAction('member.invited', 'A')]),
            $this->provider([new RegisteredAction('member.invited', 'B')]),
        ]);
    }

    public function test_rejects_malformed_action_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RegisteredAction('Member Invited!', 'bad');
    }

    /**
     * @param list<RegisteredAction> $actions
     */
    private function provider(array $actions): AuditActionProviderInterface
    {
        return new class($actions) implements AuditActionProviderInterface {
            /** @param list<RegisteredAction> $actions */
            public function __construct(private readonly array $actions) {}

            public function actions(): array
            {
                return $this->actions;
            }
        };
    }
}
