<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Fixtures;

use Vortos\Observability\Heartbeat\HeartbeatEmitterInterface;
use Vortos\Observability\Heartbeat\HeartbeatPing;

final class FakeHeartbeatEmitter implements HeartbeatEmitterInterface
{
    /** @var list<HeartbeatPing> */
    private array $received = [];

    public function __construct(private readonly bool $acknowledge = true) {}

    public function emit(HeartbeatPing $ping): bool
    {
        $this->received[] = $ping;

        return $this->acknowledge;
    }

    /** @return list<HeartbeatPing> */
    public function received(): array
    {
        return $this->received;
    }
}
