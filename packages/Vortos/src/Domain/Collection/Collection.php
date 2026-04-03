<?php

namespace Vortos\Domain\Collection;

use Vortos\Domain\ValueObject\ValueObject;

/**
 * Abstract base class for typed domain collections.
 *
 * Enforces type safety at runtime — only items matching itemType()
 * can be added. Subclasses declare the accepted type and nothing else.
 * The collection is ordered and supports value-based equality checks
 * for ValueObject items via equals().
 *
 * Usage:
 *   final class OrderLines extends Collection
 *   {
 *       protected function itemType(): string { return OrderLine::class; }
 *   }
 *
 * @template T
 */
abstract class Collection implements \Countable, \IteratorAggregate
{
    /** @var array<int, mixed> */
    private array $items = [];

    /**
     * The fully qualified class name this collection accepts.
     * Enforced on add(). Override in subclass:
     * 
     * protected function itemType(): string { return OrderLine::class; }
     */
    abstract protected function itemType(): string;

    /**
     * Add an item. Throws if item is not of itemType().
     * 
     * @throws \InvalidArgumentException for wrong type
     */
    public function add(mixed $item): void
    {
        $itemType = $this->itemType();

        if (!$item instanceof $itemType) {
            throw new \InvalidArgumentException("Provided items type doesnt match with collections type");
        }

        $this->items[] = $item;
    }

    /**
     * Remove an item by value equality if item implements equals(),
     * or by strict comparison otherwise.
     */
    public function remove(mixed $item): void
    {
        $itemType = $this->itemType();

        if (!$item instanceof $itemType) {
            return;
        }

        $this->items = array_values(array_filter($this->items, function ($currentItem) use ($item) {
            if ($item instanceof ValueObject) {
                return !$item->equals($currentItem);
            }

            return $item !== $currentItem;
        }));
    }

    /**
     * Check if item exists in collection.
     */
    public function contains(mixed $item): bool
    {
        foreach ($this->items as $existing) {
            if ($item instanceof ValueObject && $existing instanceof ValueObject) {
                if ($item->equals($existing)) return true;
            } elseif ($existing === $item) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a plain PHP array copy.
     * Modifications to the returned array do not affect the collection.
     */
    public function toArray(): array 
    {
        return $this->items;
    }

    public function count(): int 
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function isEmpty(): bool 
    {
        return empty($this->items);
    }

    /**
     * Returns a new collection containing only items matching the predicate.
     * Does not modify the original collection.
     */
    public function filter(callable $predicate): static
    {
        $new = new static();
        foreach ($this->items as $item) {
            if ($predicate($item)) {
                $new->items[] = $item;
            }
        }
        return $new;
    }

    /**
     * Returns the first item matching the predicate, or null.
     */
    public function first(callable $predicate): mixed 
    {
        foreach ($this->items as $item) {
            if($predicate($item)){
                return $item;
            }
        }

        return null;
    }
}
