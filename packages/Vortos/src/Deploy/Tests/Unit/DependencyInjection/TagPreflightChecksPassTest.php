<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Deploy\DependencyInjection\Compiler\TagPreflightChecksPass;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

final class TagPreflightChecksPassTest extends TestCase
{
    /**
     * The regression this pass exists for. A package registering a check with a plain register()
     * call — the way every cross-package check was written — produced a service the preflight
     * runner never collected. The gate reported nothing because it never ran, which is
     * indistinguishable from the gate passing.
     */
    public function test_a_plainly_registered_check_is_tagged(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('some.check', new Definition(FakePreflightCheck::class));

        (new TagPreflightChecksPass())->process($container);

        self::assertTrue(
            $container->getDefinition('some.check')->hasTag(TagPreflightChecksPass::TAG),
            'a registered PreflightCheckInterface service must be collected by the runner',
        );
    }

    public function test_a_non_check_service_is_left_alone(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('unrelated', new Definition(\stdClass::class));

        (new TagPreflightChecksPass())->process($container);

        self::assertFalse($container->getDefinition('unrelated')->hasTag(TagPreflightChecksPass::TAG));
    }

    /** Tagging twice would run the same gate twice and report it twice. */
    public function test_an_already_tagged_check_is_not_tagged_again(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(FakePreflightCheck::class);
        $definition->addTag(TagPreflightChecksPass::TAG);
        $container->setDefinition('some.check', $definition);

        (new TagPreflightChecksPass())->process($container);

        self::assertCount(1, $container->getDefinition('some.check')->getTag(TagPreflightChecksPass::TAG));
    }

    /**
     * Autoconfiguration prototypes are not real services. Tagging them injects references the
     * runner cannot instantiate.
     */
    public function test_instanceof_prototypes_are_skipped(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('.instanceof.' . PreflightCheckInterface::class . '.0.some.check', new Definition(FakePreflightCheck::class));
        $container->setDefinition('.abstract.instanceof.some.check', new Definition(FakePreflightCheck::class));

        (new TagPreflightChecksPass())->process($container);

        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, '.')) {
                self::assertFalse($definition->hasTag(TagPreflightChecksPass::TAG), $id);
            }
        }
    }

    public function test_abstract_definitions_are_skipped(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(FakePreflightCheck::class);
        $definition->setAbstract(true);
        $container->setDefinition('abstract.check', $definition);

        (new TagPreflightChecksPass())->process($container);

        self::assertFalse($container->getDefinition('abstract.check')->hasTag(TagPreflightChecksPass::TAG));
    }

    /** A %parameter%-driven class cannot be resolved here; guessing would be worse than skipping. */
    public function test_unresolvable_classes_do_not_break_compilation(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('param.class', new Definition('%some.class.param%'));
        $container->setDefinition('missing.class', new Definition('Vortos\\Nope\\DoesNotExist'));

        (new TagPreflightChecksPass())->process($container);

        self::assertFalse($container->getDefinition('param.class')->hasTag(TagPreflightChecksPass::TAG));
        self::assertFalse($container->getDefinition('missing.class')->hasTag(TagPreflightChecksPass::TAG));
    }
}

final class FakePreflightCheck implements PreflightCheckInterface
{
    public function id(): string
    {
        return 'fake';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        return PreflightFinding::pass($this->id(), $this->category(), 'fine');
    }
}
