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
use Vortos\Pipeline\Model\PinnedImage;
use Vortos\Pipeline\Model\ProjectPath;

/** RC-5: pinned image declarations the pipeline could not build and verify safely are refused. */
final class PipelineDefinitionPinnedImagesTest extends TestCase
{
    private const GATED = [
        'imageRepository' => 'docker.io/acme/app',
        'nativeRunnerLabel' => 'ubuntu-24.04-arm',
        'emitScanGate' => true,
        'emitSign' => true,
        'verifySignatureBeforeRelease' => true,
        'syncComposeTopology' => true,
    ];

    private static function postgres(string $name = 'postgres', string $service = 'write_db'): PinnedImage
    {
        return new PinnedImage(new AuxiliaryImageName($name), new ProjectPath('docker/postgres/Dockerfile'), new ProjectPath('docker/postgres'), new ComposeServiceSet([$service]));
    }

    public function test_the_builder_propagates_and_serialises_it(): void
    {
        $definition = PipelineDefinitionBuilder::create()
            ->imageRepository('docker.io/acme/app')
            ->nativeRunnerLabel('ubuntu-24.04-arm')
            ->emitScanGate(true)
            ->emitSign(true)
            ->verifySignatureBeforeRelease(true)
            ->syncComposeTopology(true, apply: true)
            ->pinnedImages(self::postgres())
            ->build();

        self::assertSame(
            [['name' => 'postgres', 'dockerfile' => 'docker/postgres/Dockerfile', 'context' => 'docker/postgres', 'services' => ['write_db'], 'scan_ignore_file' => null]],
            $definition->toArray()['pinned_images'],
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unsafe(): iterable
    {
        yield 'no repository' => [['imageRepository' => null] + self::GATED, 'set imageRepository'];
        yield 'no scan gate' => [['emitScanGate' => false] + self::GATED, 'trusted only through its signature'];
        yield 'unsigned' => [['emitSign' => false] + self::GATED, 'trusted only through its signature'];
        yield 'not re-verified before release' => [['verifySignatureBeforeRelease' => false] + self::GATED, 'trusted only through its signature'];
        yield 'no topology sync' => [['syncComposeTopology' => false] + self::GATED, 'enable syncComposeTopology'];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('unsafe')]
    public function test_a_pinned_image_without_every_gate_is_refused(array $args, string $message): void
    {
        $this->expectExceptionMessage($message);

        new PipelineDefinition(...$args, pinnedImages: [self::postgres()]);
    }

    public function test_a_name_or_service_may_not_be_shared_with_an_auxiliary_image(): void
    {
        $aux = static fn (string $name, string $service): AuxiliaryImage => new AuxiliaryImage(new AuxiliaryImageName($name), new ProjectPath('docker/backup/Dockerfile'), 'IMAGE', new ComposeServiceSet([$service]));

        try {
            new PipelineDefinition(...self::GATED, auxiliaryImages: [$aux('postgres', 'backup-scheduler')], pinnedImages: [self::postgres()]);
            self::fail('a shared name was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('declared more than once', $e->getMessage());
        }

        $this->expectExceptionMessage('more than one pinned or auxiliary image');
        new PipelineDefinition(...self::GATED, auxiliaryImages: [$aux('backup', 'write_db')], pinnedImages: [self::postgres()]);
    }

    public function test_the_build_tag_is_a_sibling_in_the_repository(): void
    {
        self::assertSame('docker.io/acme/app:pinned-postgres-${{ github.sha }}', self::postgres()->buildTag('docker.io/acme/app'));
    }

    public function test_an_unsafe_scan_ignore_file_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PinnedImage(new AuxiliaryImageName('postgres'), new ProjectPath('docker/postgres/Dockerfile'), new ProjectPath('docker/postgres'), new ComposeServiceSet(['write_db']), '../etc/x');
    }
}
