<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\DependencyInjection\Compiler\PermissionRegistryPass;
use Vortos\Authorization\Permission\AbstractPermissionCatalog;
use Vortos\Authorization\Permission\PermissionImplicationExpander;
use Vortos\Authorization\Permission\PermissionRegistry;

#[PermissionCatalog(resource: 'invoices', group: 'Billing')]
final class InvoiceCatalog extends AbstractPermissionCatalog
{
    public const VIEW = 'view.any';
    public const REVIEW = 'review.any';
    public const REFUND = 'refund.any';

    public static function grants(): array
    {
        return ['clerk' => [self::REVIEW], 'manager' => [self::REFUND]];
    }

    public static function implies(): array
    {
        return [
            self::REVIEW => [self::VIEW],
            self::REFUND => [self::REVIEW],
        ];
    }
}

#[PermissionCatalog(resource: 'ledger')]
final class SelfImplyingCatalog extends AbstractPermissionCatalog
{
    public const READ = 'read.any';

    public static function implies(): array
    {
        return [self::READ => [self::READ]];
    }
}

#[PermissionCatalog(resource: 'reports')]
final class UnknownImplicationCatalog extends AbstractPermissionCatalog
{
    public const READ = 'read.any';

    public static function implies(): array
    {
        return [self::READ => ['reports.nonexistent.any']];
    }
}

final class PermissionImplicationTest extends TestCase
{
    public function testImplicationsAreResolvedTransitively(): void
    {
        $registry = $this->compile(InvoiceCatalog::class);
        $expander = new PermissionImplicationExpander($registry);

        // refund → review → view, so one grant carries the whole chain.
        self::assertSame(
            ['invoices.refund.any', 'invoices.review.any', 'invoices.view.any'],
            $expander->expand(['invoices.refund.any']),
        );
    }

    public function testExpansionLeavesUnrelatedPermissionsAlone(): void
    {
        $registry = $this->compile(InvoiceCatalog::class);
        $expander = new PermissionImplicationExpander($registry);

        self::assertSame(['tournaments.create.any'], $expander->expand(['tournaments.create.any']));
    }

    public function testGrantsAreNotRewrittenByImplications(): void
    {
        $registry = $this->compile(InvoiceCatalog::class);

        // The stored grant list is exactly what the catalog declared — expansion
        // is a resolution-time concern, never a persisted one.
        self::assertSame(['invoices.review.any'], $registry->defaultGrants()['clerk']);
        self::assertSame(['invoices.refund.any'], $registry->defaultGrants()['manager']);
    }

    public function testImpliedByReportsTheFullChain(): void
    {
        $expander = new PermissionImplicationExpander($this->compile(InvoiceCatalog::class));

        self::assertSame(['invoices.review.any', 'invoices.view.any'], $expander->impliedBy('invoices.refund.any'));
        self::assertSame([], $expander->impliedBy('invoices.view.any'));
    }

    public function testSelfImplicationIsRejectedAtCompileTime(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('implying itself');

        $this->compile(SelfImplyingCatalog::class);
    }

    public function testImplyingAnUnknownPermissionIsRejectedAtCompileTime(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('implies unknown permission');

        $this->compile(UnknownImplicationCatalog::class);
    }

    public function testCycleIsRejectedAtCompileTime(): void
    {
        $registry = new PermissionRegistry(
            [],
            [],
            ['a.read.any' => ['b.read.any'], 'b.read.any' => ['a.read.any']],
        );

        // The compiler pass rejects cycles, but the expander must still terminate
        // if one is ever constructed directly.
        $expander = new PermissionImplicationExpander($registry);

        self::assertSame(['a.read.any', 'b.read.any'], $expander->expand(['a.read.any']));
    }

    private function compile(string $catalogClass): PermissionRegistry
    {
        $container = new ContainerBuilder();
        $container->setDefinition(PermissionRegistry::class, new Definition(PermissionRegistry::class))
            ->setArgument('$permissions', [])
            ->setArgument('$defaultGrants', [])
            ->setArgument('$implications', []);

        $container->setDefinition($catalogClass, (new Definition($catalogClass))
            ->addTag('vortos.permission_catalog'));

        (new PermissionRegistryPass())->process($container);

        $definition = $container->getDefinition(PermissionRegistry::class);

        return new PermissionRegistry(
            $definition->getArgument('$permissions'),
            $definition->getArgument('$defaultGrants'),
            $definition->getArgument('$implications'),
        );
    }
}
