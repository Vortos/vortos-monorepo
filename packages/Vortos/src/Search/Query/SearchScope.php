<?php

declare(strict_types=1);

namespace Vortos\Search\Query;

/**
 * Who is asking — the caller's authorization envelope, resolved by the app at the HTTP edge
 * and passed into every query. The engine applies it uniformly so a caller can only ever see:
 *   - rows in their own {@see $tenantId} (also RLS-enforced at the DB), AND
 *   - org-shared rows whose {@see SearchDocument::$permission} they hold (or that have none), AND
 *   - personal rows they own.
 *
 * There is no "unscoped" query — constructing a scope is mandatory to read the index.
 */
final class SearchScope
{
    /**
     * @param string       $tenantId    the caller's org; the hard isolation boundary
     * @param string|null  $memberId    the caller; unlocks their personal (owned) rows. null = no personal rows
     * @param list<string> $permissions permission keys the caller holds; gates org-shared rows
     * @param bool         $superuser   platform/cross-tenant reader: bypasses the permission gate
     *                                  (NOT the tenant boundary unless $tenantId is empty). Off by default.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $memberId = null,
        public readonly array $permissions = [],
        public readonly bool $superuser = false,
    ) {
        if ($tenantId === '' && !$superuser) {
            throw new \InvalidArgumentException('A non-superuser SearchScope requires a tenantId.');
        }
    }

    /** Stable fingerprint of the visibility envelope — used to key the per-caller query cache. */
    public function cacheFingerprint(): string
    {
        $perms = $this->permissions;
        sort($perms);

        return substr(hash('xxh128', implode('|', [
            $this->tenantId,
            $this->memberId ?? '',
            $this->superuser ? '1' : '0',
            implode(',', $perms),
        ])), 0, 16);
    }
}
