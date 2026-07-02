<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Model\ServiceContainer;

/**
 * Guards against the builder drifting behind the value object it builds — the exact defect this
 * change fixed (the builder was missing 9 of {@see PipelineDefinition}'s constructor params).
 *
 * The builder maps its private properties onto ctor params by name in build(), so a matching
 * property per param is the invariant that keeps `build()` total.
 */
final class PipelineDefinitionBuilderCompletenessTest extends TestCase
{
    public function test_builder_has_a_property_for_every_pipeline_definition_param(): void
    {
        $ctor = (new \ReflectionClass(PipelineDefinition::class))->getConstructor();
        self::assertNotNull($ctor);

        $builderProps = [];
        foreach ((new \ReflectionClass(PipelineDefinitionBuilder::class))->getProperties() as $prop) {
            $builderProps[] = $prop->getName();
        }

        foreach ($ctor->getParameters() as $param) {
            self::assertContains(
                $param->getName(),
                $builderProps,
                sprintf(
                    'PipelineDefinitionBuilder is missing a property/setter for '
                    . 'PipelineDefinition constructor param $%s — the builder has drifted behind the VO.',
                    $param->getName(),
                ),
            );
        }
    }

    public function test_create_returns_defaults_equivalent_to_bare_definition(): void
    {
        self::assertEquals(new PipelineDefinition(), PipelineDefinitionBuilder::create()->build());
    }

    public function test_all_previously_missing_setters_propagate(): void
    {
        $container = ServiceContainer::fromArray(['name' => 'redis', 'image' => 'redis:7']);

        $def = PipelineDefinitionBuilder::create()
            ->emitScanGate(true)
            ->emitSign(true)
            ->registryProvider('dockerhub')
            ->workflowFilename('release.yml')
            ->workflowName('Release')
            ->testCommand('composer test')
            ->analyseCommand('composer analyse')
            ->testServiceContainers([$container])
            ->testSteps([['name' => 'migrate', 'run' => 'bin/console migrate']])
            ->build();

        self::assertTrue($def->emitScanGate);
        self::assertTrue($def->emitSign);
        self::assertSame('dockerhub', $def->registryProvider);
        self::assertSame('release.yml', $def->workflowFilename);
        self::assertSame('Release', $def->workflowName);
        self::assertSame('composer test', $def->testCommand);
        self::assertSame('composer analyse', $def->analyseCommand);
        self::assertSame([$container], $def->testServiceContainers);
        self::assertSame([['name' => 'migrate', 'run' => 'bin/console migrate']], $def->testSteps);
    }
}
