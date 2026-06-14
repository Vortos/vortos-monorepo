<?php

declare(strict_types=1);

namespace Vortos\Logger\HashChain;

use Vortos\Logger\Contract\HashChainStateInterface;

/**
 * Default per-process hash chain state. See HashChainStateInterface for the
 * cross-process caveat.
 */
final class InMemoryHashChainState implements HashChainStateInterface
{
    public const GENESIS = 'vortos-audit-chain-genesis';

    private string $last = self::GENESIS;

    public function last(): string
    {
        return $this->last;
    }

    public function setLast(string $hash): void
    {
        $this->last = $hash;
    }
}
