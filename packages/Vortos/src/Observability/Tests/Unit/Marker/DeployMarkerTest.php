<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Marker;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Marker\AnnotationRenderer;
use Vortos\Observability\Marker\DeployMarker;

final class DeployMarkerTest extends TestCase
{
    private function makeMarker(string $buildId = 'build-1'): DeployMarker
    {
        return new DeployMarker(
            env: 'prod',
            kind: 'deploy',
            buildId: $buildId,
            gitSha: 'abc123',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            schemaFingerprintId: 'fp-1',
            title: 'Deployed: prod',
            tags: ['succeeded'],
            at: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            links: ['manifest' => 'https://example.invalid/manifests/build-1'],
        );
    }

    public function test_rejects_invalid_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeployMarker('prod', 'bogus', 'b1', 'sha', 'sha256:' . str_repeat('a', 64), 'fp', 't', [], new \DateTimeImmutable());
    }

    public function test_idempotency_key_is_stable_for_same_env_kind_build(): void
    {
        $m1 = $this->makeMarker();
        $m2 = $this->makeMarker();

        self::assertSame($m1->idempotencyKey(), $m2->idempotencyKey());
    }

    public function test_idempotency_key_differs_for_different_build(): void
    {
        $m1 = $this->makeMarker('build-1');
        $m2 = $this->makeMarker('build-2');

        self::assertNotSame($m1->idempotencyKey(), $m2->idempotencyKey());
    }

    public function test_annotation_renderer_output_has_no_secret_and_links_the_manifest(): void
    {
        $renderer = new AnnotationRenderer();
        $rendered = $renderer->render($this->makeMarker());

        self::assertSame('prod', $rendered['attributes']['deploy.env']);
        self::assertSame('https://example.invalid/manifests/build-1', $rendered['attributes']['deploy.links']['manifest']);
        self::assertArrayNotHasKey('password', $rendered);
        self::assertArrayNotHasKey('secret', $rendered);
    }

    public function test_annotation_renderer_is_deterministic(): void
    {
        $renderer = new AnnotationRenderer();
        $marker = $this->makeMarker();

        self::assertSame($renderer->render($marker), $renderer->render($marker));
    }
}
