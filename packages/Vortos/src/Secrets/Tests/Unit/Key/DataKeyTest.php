<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Key;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Key\DataKey;

final class DataKeyTest extends TestCase
{
    public function test_from_raw_round_trips_through_reveal(): void
    {
        $raw = random_bytes(32);

        $dataKey = DataKey::fromRaw($raw);

        self::assertSame($raw, $dataKey->revealForEncryption());
    }

    public function test_rejects_empty_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DataKey::fromRaw('');
    }

    public function test_wipe_makes_reveal_throw(): void
    {
        $dataKey = DataKey::fromRaw(random_bytes(32));

        $dataKey->wipe();

        $this->expectException(\Vortos\Secrets\Exception\SecretAlreadyWipedException::class);
        $dataKey->revealForEncryption();
    }

    public function test_round_trips_binary_with_null_bytes(): void
    {
        $raw = "\x00\x01\x02\x00\xff";

        $dataKey = DataKey::fromRaw($raw);

        self::assertSame($raw, $dataKey->revealForEncryption());
    }
}
