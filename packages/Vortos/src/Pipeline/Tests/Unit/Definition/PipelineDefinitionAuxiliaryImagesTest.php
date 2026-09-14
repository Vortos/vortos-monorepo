<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Model\AuxiliaryImage;
use Vortos\Pipeline\Model\AuxiliaryImageName;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\ProjectPath;
use Vortos\Pipeline\Topology\ImageProvenanceRule;
use Vortos\Pipeline\Topology\TopologyPolicy;

/** RC-9: auxiliary image declarations the pipeline could not build and verify safely are refused. */
final class PipelineDefinitionAuxiliaryImagesTest extends TestCase
{
    private static function backup(string $name = 'backup', string $service = 'backup-scheduler'): AuxiliaryImage
    {
        return new AuxiliaryImage(new AuxiliaryImageName($name), new ProjectPath('docker/backup/Dockerfile'), 'IMAGE', new ComposeServiceSet([$service]));
    }

    public function test_the_builder_propagates_and_the_policy_carries_the_rule(): void
    {
        $definition = PipelineDefinitionBuilder::create()
            ->imageRepository('docker.io/acme/app')
            ->nativeRunnerLabel('ubuntu-24.04-arm')
            ->emitScanGate(true)
            ->emitSign(true)
            ->syncComposeTopology(true, apply: true)
            ->auxiliaryImages(self::backup())
            ->build();

        self::assertSame('backup', $definition->auxiliaryImages[0]->name->value);
        self::assertSame(
            [['name' => 'backup', 'dockerfile' => 'docker/backup/Dockerfile', 'release_image_arg' => 'IMAGE', 'services' => ['backup-scheduler'], 'scan_ignore_file' => null]],
            $definition->toArray()['auxiliary_images'],
        );
        self::assertContains(ImageProvenanceRule::NAME, TopologyPolicy::forDefinition($definition)->ruleNames());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unsafe(): iterable
    {
        $gated = ['imageRepository' => 'docker.io/acme/app', 'nativeRunnerLabel' => 'ubuntu-24.04-arm', 'emitScanGate' => true, 'emitSign' => true, 'syncComposeTopology' => true];
        yield 'no repository' => [['emitScanGate' => true, 'emitSign' => true, 'syncComposeTopology' => true], 'set imageRepository'];
        yield 'no scan gate' => [['emitScanGate' => false] + $gated, 'CVE-gated and signed'];
        yield 'unsigned' => [['emitSign' => false] + $gated, 'CVE-gated and signed'];
        yield 'no topology sync' => [['syncComposeTopology' => false] + $gated, 'enable syncComposeTopology'];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('unsafe')]
    public function test_an_auxiliary_image_without_every_gate_is_refused(array $args, string $message): void
    {
        $this->expectExceptionMessage($message);

        new PipelineDefinition(...$args, auxiliaryImages: [self::backup()]);
    }

    public function test_names_and_services_are_declared_once(): void
    {
        $gated = ['imageRepository' => 'docker.io/acme/app', 'nativeRunnerLabel' => 'ubuntu-24.04-arm', 'emitScanGate' => true, 'emitSign' => true, 'syncComposeTopology' => true];

        try {
            new PipelineDefinition(...$gated, auxiliaryImages: [self::backup(), self::backup(service: 'other')]);
            self::fail('duplicate name accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('declared twice', $e->getMessage());
        }

        $this->expectExceptionMessage('more than one auxiliary image');
        new PipelineDefinition(...$gated, auxiliaryImages: [self::backup(), self::backup('tools')]);
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'reserved ops' => ['ops'];
        yield 'uppercase' => ['Backup'];
        yield 'dash' => ['back-up'];
        yield 'one char' => ['b'];
        yield 'leading digit' => ['1backup'];
    }

    #[DataProvider('badNames')]
    public function test_names_that_would_break_tags_ids_or_collide_are_refused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuxiliaryImageName($name);
    }

    public function test_the_name_derives_the_compose_variable_and_output(): void
    {
        $name = new AuxiliaryImageName('backup');

        self::assertSame('VORTOS_IMAGE_BACKUP', $name->composeVariable());
        self::assertSame('auxbackup', $name->outputName());
    }

    public function test_the_release_image_arg_must_be_upper_snake(): void
    {
        $this->expectExceptionMessage('UPPER_SNAKE');

        new AuxiliaryImage(new AuxiliaryImageName('backup'), new ProjectPath('docker/backup/Dockerfile'), 'image; rm', new ComposeServiceSet(['x']));
    }
}
