<?php

declare(strict_types=1);

namespace Vortos\Analytics\Runtime;

use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;

/**
 * A bounded LRU keyed by a VO's content hash, making repeated `identify`/`group`
 * calls with identical content within the window idempotent — they collapse to a
 * single forward, respecting provider rate limits without app-code complexity.
 *
 * Bounded by `$maxEntries` (default 1000): the oldest entry is evicted once the
 * cache is full, so a high-cardinality stream of distinct identities can never grow
 * memory unbounded.
 */
final class IdentityDedupeCache
{
    /** @var array<string,true> */
    private array $seen = [];

    /** @var list<string> oldest-first */
    private array $order = [];

    public function __construct(private readonly int $maxEntries = 1000) {}

    /** Returns true if this exact identity content was already seen (within window). */
    public function seenIdentity(IdentitySet $identity): bool
    {
        return $this->checkAndRemember($identity->contentHash());
    }

    /** Returns true if this exact group content was already seen (within window). */
    public function seenGroup(GroupAssociation $group): bool
    {
        return $this->checkAndRemember($group->contentHash());
    }

    private function checkAndRemember(string $hash): bool
    {
        if (isset($this->seen[$hash])) {
            $this->touch($hash);

            return true;
        }

        $this->remember($hash);

        return false;
    }

    private function remember(string $hash): void
    {
        $this->seen[$hash] = true;
        $this->order[] = $hash;

        if (count($this->order) > $this->maxEntries) {
            // count() > 0 here, so array_shift() always returns the evicted hash.
            $evicted = array_shift($this->order);
            unset($this->seen[$evicted]);
        }
    }

    private function touch(string $hash): void
    {
        $position = array_search($hash, $this->order, true);
        if ($position !== false) {
            unset($this->order[$position]);
            $this->order[] = $hash;
        }
    }
}
