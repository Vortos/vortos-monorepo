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
