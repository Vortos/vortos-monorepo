<?php

declare(strict_types=1);

namespace Vortos\Release\Tagging\File;

use Vortos\Release\Tagging\TaggingTransaction;
use Vortos\Release\Tagging\TaggingTransactionStoreInterface;

final class FileTaggingTransactionStore implements TaggingTransactionStoreInterface
{
    public function __construct(private readonly string $directory) {}

    public function save(TaggingTransaction $transaction): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o755, true);
        }

        $path = $this->pathFor($transaction->id);
        $json = json_encode($transaction->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($path, $json . "\n", LOCK_EX);
    }

    public function load(string $id): ?TaggingTransaction
    {
        $path = $this->pathFor($id);

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return TaggingTransaction::fromArray($data);
    }

    public function list(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $transactions = [];
        $files = glob($this->directory . '/*.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $transactions[] = TaggingTransaction::fromArray($data);
        }

        return $transactions;
    }

    private function pathFor(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }
}
