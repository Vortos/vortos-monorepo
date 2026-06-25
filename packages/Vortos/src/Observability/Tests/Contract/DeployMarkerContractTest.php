<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Marker\AnnotationRenderer;
use Vortos\Observability\Marker\DeployMarker;

/**
 * Pinned golden vector for the rendered deploy-marker annotation (Block 16, §6) —
 * structure + key fields are locked so a rendering regression is caught immediately.
 */
final class DeployMarkerContractTest extends TestCase
{
    public function test_rendered_annotation_matches_pinned_vector(): void
    {
        $marker = new DeployMarker(
            env: 'prod',
            kind: 'deploy',
            buildId: 'build-42',
            gitSha: 'deadbee',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            schemaFingerprintId: 'fp-7',
            title: 'Deployed: prod',
            tags: ['succeeded'],
            at: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            links: ['manifest' => 'https://example.invalid/manifests/build-42'],
        );

        $rendered = (new AnnotationRenderer())->render($marker);

        self::assertSame([
            'timeUnixNano' => (string) (1767225600 * 1_000_000_000),
            'severityText' => 'INFO',
            'body' => 'Deployed: prod',
            'attributes' => [
                'deploy.env' => 'prod',
                'deploy.kind' => 'deploy',
                'deploy.build_id' => 'build-42',
                'deploy.git_sha' => 'deadbee',
                'deploy.image_digest' => 'sha256:' . str_repeat('a', 64),
                'deploy.schema_fingerprint_id' => 'fp-7',
                'deploy.tags' => ['succeeded'],
                'deploy.links' => ['manifest' => 'https://example.invalid/manifests/build-42'],
                'deploy.idempotency_key' => hash('sha256', 'prod|deploy|build-42'),
            ],
        ], $rendered);
    }
}
