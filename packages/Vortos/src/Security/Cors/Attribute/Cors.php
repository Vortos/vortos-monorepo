<?php

declare(strict_types=1);

namespace Vortos\Security\Cors\Attribute;

use Attribute;

/**
 * Per-route CORS override.
 *
 * When placed on a controller class or action method, the values here take
 * precedence over the global CORS config for that route.
 *
 * Example — allow a public read-only endpoint from any origin:
 *
 *   #[Cors(origins: ['*'], methods: ['GET'], credentials: false)]
 *   class PublicFeedController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Cors
{
    /**
     * @param list<string>|null $origins         Allowed origins. '*' = any origin.
     * @param list<string>|null $methods         Allowed HTTP methods.
     * @param list<string>|null $allowedHeaders  Headers the browser may send.
     * @param list<string>|null $exposedHeaders  Headers the browser may read.
     * @param bool|null         $credentials     Whether to allow credentials.
     * @param int|null          $maxAge          Preflight cache duration in seconds.
     */
    public function __construct(
        public readonly ?array $origins        = null,
        public readonly ?array $methods        = null,
        public readonly ?array $allowedHeaders = null,
        public readonly ?array $exposedHeaders = null,
        public readonly ?bool  $credentials    = null,
        public readonly ?int   $maxAge         = null,
    ) {}
}
