<?php

declare(strict_types=1);

namespace Vortos\Debug\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Debug\Command\DebugContainerCommand;
use Vortos\Debug\DependencyInjection\DebugPackage;

/**
 * `vortos:debug:container` must describe the container that RUNS, not an intermediate one.
 *
 * DebugContainerPass used to run at BEFORE_OPTIMIZATION priority 60, ahead of every
 * negative-priority pass and every removal pass. It therefore reported services that later passes
 * superseded and removal deleted, and omitted services that later passes registered. Concretely it
 * listed InMemoryAlertStateStore (gone at runtime), omitted AlertAuditRecorder (present at runtime),
 * and showed 19 services tagged vortos.deploy.preflight_check where 25 carry it — missing exactly
 * the six added by TagPreflightChecksPass at -48.
 *
 * That is worse than having no debug command. It was twice read as evidence that a fix had not
 * worked, when the fix was fine and the reporting was wrong.
 */
final class DebugContainerSnapshotTest extends TestCase
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function compileAndReadSnapshot(ContainerBuilder $container): array
    {
        (new DebugPackage())->build($container);
        $container->register(DebugContainerCommand::class, DebugContainerCommand::class)
            ->setArguments([[], []])
            ->setPublic(true);
        $container->compile();

        $args = $container->getDefinition(DebugContainerCommand::class)->getArguments();

        return [$args[0], $args[1]];
    }

    public function test_snapshot_includes_services_registered_by_a_late_pass(): void
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(
            new class implements CompilerPassInterface {
                public function process(ContainerBuilder $c): void
                {
                    $c->register('late.service', \stdClass::class)->setPublic(true);
                }
            },
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -48, // the priority TagPreflightChecksPass uses
        );

        [$services] = $this->compileAndReadSnapshot($container);

        self::assertArrayHasKey(
            'late.service',
            $services,
            'A service registered by a late compiler pass exists at runtime and must be reported.',
        );
    }

    public function test_snapshot_excludes_services_removed_during_compilation(): void
    {
        $container = new ContainerBuilder();
        // Private and referenced by nothing — Symfony removes it, so it does not exist at runtime.
        $container->register('unused.private', \stdClass::class)->setPublic(false);

        [$services] = $this->compileAndReadSnapshot($container);

        self::assertArrayNotHasKey(
            'unused.private',
            $services,
            'A service removed during compilation does not exist at runtime and must not be '
            . 'reported as if it did — that is precisely how the old snapshot misled.',
        );
    }

    public function test_snapshot_reflects_a_tag_added_by_a_late_pass(): void
    {
        $container = new ContainerBuilder();
        $container->register('tagged.later', \stdClass::class)->setPublic(true);
        $container->addCompilerPass(
            new class implements CompilerPassInterface {
                public function process(ContainerBuilder $c): void
                {
                    $c->getDefinition('tagged.later')->addTag('vortos.deploy.preflight_check');
                }
            },
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -48,
        );

        [$services] = $this->compileAndReadSnapshot($container);

        self::assertContains(
            'vortos.deploy.preflight_check',
            $services['tagged.later']['tags'],
            'Tags applied by a late pass decide whether a deploy gate runs; the snapshot must show them.',
        );
    }

    public function test_argument_names_survive_named_argument_resolution(): void
    {
        $container = new ContainerBuilder();
        $container->register('dep', \stdClass::class)->setPublic(true);
        $container->register('consumer', DebugSnapshotFixture::class)
            ->setArgument('$collaborator', new Reference('dep'))
            ->setPublic(true);

        [$services] = $this->compileAndReadSnapshot($container);

        self::assertSame(
            ['$collaborator' => '@dep'],
            $services['consumer']['args'],
            'Running after named-argument resolution must not reduce the detail view to numeric '
            . 'indices — the names are recovered by reflecting the constructor.',
        );
    }
}

final class DebugSnapshotFixture
{
    public function __construct(public readonly \stdClass $collaborator)
    {
    }
}
