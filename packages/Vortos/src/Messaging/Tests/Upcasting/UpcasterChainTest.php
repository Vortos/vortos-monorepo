<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Upcasting;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Upcasting\UpcasterChain;
use Vortos\Messaging\Upcasting\UpcasterInterface;

final class SplitNameV1ToV2 implements UpcasterInterface
{
    public function upcast(array $payload): array
    {
        [$first, $last] = array_pad(explode(' ', (string) $payload['name'], 2), 2, '');
        unset($payload['name']);
        return $payload + ['firstName' => $first, 'lastName' => $last];
    }
}

final class AddCountryV2ToV3 implements UpcasterInterface
{
    public function upcast(array $payload): array
    {
        return $payload + ['country' => 'unknown'];
    }
}

final class UpcasterChainTest extends TestCase
{
    private function chain(): UpcasterChain
    {
        return new UpcasterChain([
            'registration.entry_approved' => [
                1 => SplitNameV1ToV2::class,
                2 => AddCountryV2ToV3::class,
            ],
        ]);
    }

    public function test_single_step(): void
    {
        $chain = new UpcasterChain(['x.y' => [1 => SplitNameV1ToV2::class]]);

        [$payload, $version] = $chain->upcast('x.y', 1, ['name' => 'Ada Lovelace', 'id' => 'e1']);

        $this->assertSame(2, $version);
        $this->assertSame(['id' => 'e1', 'firstName' => 'Ada', 'lastName' => 'Lovelace'], $payload);
    }

    public function test_walks_full_chain(): void
    {
        [$payload, $version] = $this->chain()->upcast('registration.entry_approved', 1, ['name' => 'Ada Lovelace']);

        $this->assertSame(3, $version);
        $this->assertSame('Ada', $payload['firstName']);
        $this->assertSame('unknown', $payload['country']);
    }

    public function test_starts_mid_chain(): void
    {
        [$payload, $version] = $this->chain()->upcast('registration.entry_approved', 2, ['firstName' => 'Ada', 'lastName' => 'L']);

        $this->assertSame(3, $version);
        $this->assertSame('unknown', $payload['country']);
        $this->assertArrayNotHasKey('name', $payload);
    }

    public function test_current_version_passes_through_untouched(): void
    {
        $original = ['firstName' => 'Ada', 'lastName' => 'L', 'country' => 'uk'];

        [$payload, $version] = $this->chain()->upcast('registration.entry_approved', 3, $original);

        $this->assertSame(3, $version);
        $this->assertSame($original, $payload);
    }

    public function test_unregistered_event_passes_through(): void
    {
        $this->assertFalse($this->chain()->hasStepsFor('other.event'));

        [$payload, $version] = $this->chain()->upcast('other.event', 1, ['a' => 1]);

        $this->assertSame(1, $version);
        $this->assertSame(['a' => 1], $payload);
    }
}
