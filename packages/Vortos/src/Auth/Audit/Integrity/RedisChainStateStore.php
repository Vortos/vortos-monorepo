<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Integrity;

use Vortos\Auth\Audit\AuditEntry;

final class RedisChainStateStore implements ChainStateStoreInterface
{
    private const KEY = 'vortos:auth:audit:chain_state';

    private const LUA_ADVANCE = <<<'LUA'
        local key = KEYS[1]
        local genesis = ARGV[1]
        local current = redis.call('HMGET', key, 'sequence', 'prev_hash')
        local seq = current[1] and tonumber(current[1]) or 0
        local prev = current[2] and current[2] or genesis
        return {tostring(seq), prev}
    LUA;

    private const LUA_COMMIT = <<<'LUA'
        local key = KEYS[1]
        local expected_seq = tonumber(ARGV[1])
        local new_hash = ARGV[2]
        local current_seq = redis.call('HGET', key, 'sequence')
        current_seq = current_seq and tonumber(current_seq) or 0
        if current_seq ~= expected_seq then
            return 0
        end
        redis.call('HMSET', key, 'sequence', tostring(expected_seq + 1), 'prev_hash', new_hash)
        return 1
    LUA;

    public function __construct(
        private readonly \Redis $redis,
    ) {}

    public function appendChained(callable $builder): AuditEntry
    {
        $maxRetries = 3;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            /** @var array{string, string} $state */
            $state = $this->redis->eval(
                self::LUA_ADVANCE,
                [self::KEY, AuthAuditHashChain::GENESIS_HASH],
                1,
            );

            $sequence = (int) $state[0];
            $prevHash = (string) $state[1];

            $entry = $builder($sequence, $prevHash);

            $committed = $this->redis->eval(
                self::LUA_COMMIT,
                [self::KEY, (string) $sequence, $entry->contentHash],
                1,
            );

            if ($committed) {
                return $entry;
            }
        }

        throw new \RuntimeException('Audit chain state contention: failed after ' . $maxRetries . ' attempts.');
    }
}
