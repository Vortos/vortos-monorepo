<?php

declare(strict_types=1);

namespace Vortos\Logger\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Vortos\Logger\Contract\HashChainStateInterface;

/**
 * Appends a tamper-evident hash chain to Audit channel records.
 *
 * Each record's `record_hash` = sha256(prev_hash + canonical record payload).
 * Any edit, deletion, or reordering of a historical record breaks the chain
 * from that point forward — detectable by recomputing hashes over the file
 * and comparing prev_hash/record_hash linkage.
 *
 * See HashChainStateInterface for cross-process chaining caveats.
 */
final class HashChainProcessor implements ProcessorInterface
{
    public function __construct(private readonly HashChainStateInterface $state) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $prevHash = $this->state->last();

        $payload = json_encode([
            'channel' => $record->channel,
            'level'   => $record->level->value,
            'message' => $record->message,
            'context' => $record->context,
            'datetime' => $record->datetime->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $recordHash = hash('sha256', $prevHash . '|' . $payload);

        $this->state->setLast($recordHash);

        return $record->with(extra: [
            ...$record->extra,
            'prev_hash'   => $prevHash,
            'record_hash' => $recordHash,
        ]);
    }
}
