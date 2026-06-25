<?php

declare(strict_types=1);

namespace Vortos\Release\Tagging;

interface TaggingTransactionStoreInterface
{
    public function save(TaggingTransaction $transaction): void;

    public function load(string $id): ?TaggingTransaction;

    /** @return list<TaggingTransaction> */
    public function list(): array;
}
