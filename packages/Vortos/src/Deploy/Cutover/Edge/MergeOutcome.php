<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * The result of merging the live upstream into an operator's adapted base config.
 *
 * Carries the merged config array (ready for the invariant firewall and then /load), what action was
 * taken, where the app proxy lives, and the SHA-256 of the merged JSON. The hash is the audit/drift
 * anchor: it is recorded in the ledger and compared against the on-box boot file so a manual admin
 * push or a stale file is detectable. Deterministic: same base + same desired color ⇒ identical
 * merged JSON ⇒ identical hash.
 */
final readonly class MergeOutcome
{
    public string $sha256;

    /** @param array<string, mixed> $config */
    public function __construct(
        public array $config,
        public MergeAction $action,
        public AppProxyLocation $location,
    ) {
        $this->sha256 = hash('sha256', $this->canonicalJson());
    }

    public function toJson(): string
    {
        return json_encode(
            $this->config,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        );
    }

    /**
     * Canonical (recursively sorted-key, compact) JSON used ONLY for hashing, so the audit/drift hash
     * is stable regardless of key ordering or pretty-printing. This lets the drift check re-hash the
     * on-box boot file (parsed and re-serialized in any order) and compare it to this hash. Not what
     * gets written to the edge.
     */
    private function canonicalJson(): string
    {
        return self::canonicalize($this->config);
    }

    /** @param array<string, mixed> $value */
    public static function canonicalize(array $value): string
    {
        $sorted = self::recursiveKsort($value);

        return json_encode($sorted, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode a caddy JSON config into the SAME representation the merge/canonicalize pipeline uses:
     * non-empty objects become associative arrays, but EMPTY objects are preserved as \stdClass so they
     * re-canonicalize as {} (not []). This is what lets a hash of the re-read on-disk boot file match
     * the hash recorded at cutover — {@see canonicalize} distinguishes {} from [], so decoding the boot
     * file with JSON_OBJECT_AS_ARRAY (which turns an empty object into an empty array) would report a
     * phantom drift for any config with an empty-object handler (e.g. an encode-gzip -> {"gzip":{}}).
     *
     * @return array<string, mixed>
     * @throws \JsonException
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        $normalized = self::normalizeNode($decoded);

        /** @var array<string, mixed> $out */
        $out = is_array($normalized) ? $normalized : [];

        return $out;
    }

    private static function normalizeNode(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            $map = (array) $node;

            return $map === [] ? new \stdClass() : array_map(self::normalizeNode(...), $map);
        }

        if (is_array($node)) {
            return array_map(self::normalizeNode(...), $node);
        }

        return $node;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function recursiveKsort(array $value): array
    {
        // List arrays keep their order (order is semantically significant for Caddy routes/upstreams);
        // associative maps are key-sorted so object key ordering never perturbs the hash.
        $isList = array_is_list($value);

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::recursiveKsort($v);
            }
        }

        if (!$isList) {
            ksort($value);
        }

        return $value;
    }
}
