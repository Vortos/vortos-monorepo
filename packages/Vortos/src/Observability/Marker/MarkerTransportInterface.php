<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

/**
 * Thin HTTP transport seam for marker drivers — kept separate from the rendered
 * body ({@see AnnotationRenderer}) so a driver's "how do I POST this" stays
 * independent of "what does the payload look like."
 */
interface MarkerTransportInterface
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function post(string $url, array $body, array $headers): void;
}
