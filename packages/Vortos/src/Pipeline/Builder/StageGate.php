<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Builder;

use Vortos\Pipeline\Model\StageKind;

final class StageGate
{
    private const ALWAYS_EMIT = [
        StageKind::Test,
        StageKind::StaticAnalysis,
        StageKind::Agnosticism,
        StageKind::Deploy,
        StageKind::Split,
    ];

    /** @var list<StageKind> */
    private array $gated = [];

    /** @param list<string> $enabledFutureStages */
    public function __construct(
        private readonly array $enabledFutureStages = [],
    ) {}

    public function shouldEmit(StageKind $kind): bool
    {
        if (\in_array($kind, self::ALWAYS_EMIT, true)) {
            return true;
        }

        if (\in_array($kind->value, $this->enabledFutureStages, true)) {
            return true;
        }

        if (!\in_array($kind, $this->gated, true)) {
            $this->gated[] = $kind;
        }

        return false;
    }

    /** @return list<StageKind> */
    public function gatedStages(): array
    {
        return $this->gated;
    }
}
