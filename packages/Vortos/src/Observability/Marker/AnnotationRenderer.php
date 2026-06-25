<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

/**
 * Pure render of a {@see DeployMarker} into a backend-agnostic OTLP log-record /
 * annotation event body. The concrete backend mapping (which header, which endpoint)
 * lives only in the driver; this renderer never performs I/O and never references a
 * backend name.
 */
final class AnnotationRenderer
{
    /** @return array<string, mixed> */
    public function render(DeployMarker $marker): array
    {
        return [
            'timeUnixNano' => (string) ($marker->at->getTimestamp() * 1_000_000_000),
            'severityText' => 'INFO',
            'body' => $marker->title,
            'attributes' => [
                'deploy.env' => $marker->env,
                'deploy.kind' => $marker->kind,
                'deploy.build_id' => $marker->buildId,
                'deploy.git_sha' => $marker->gitSha,
                'deploy.image_digest' => $marker->imageDigest,
                'deploy.schema_fingerprint_id' => $marker->schemaFingerprintId,
                'deploy.tags' => $marker->tags,
                'deploy.links' => $marker->links,
                'deploy.idempotency_key' => $marker->idempotencyKey(),
            ],
        ];
    }
}
