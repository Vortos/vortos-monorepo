<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Runtime\FileScheduleStateStore;
use Vortos\Backup\Runtime\ScheduleState;

final class FileScheduleStateStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/vortos_backup_state_' . uniqid('', true) . '/state.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
            @rmdir(\dirname($this->path));
        }
    }

    public function test_unknown_schedule_returns_empty_state(): void
    {
        $store = new FileScheduleStateStore($this->path);
        $state = $store->get('nightly');

        $this->assertNull($state->lastFiredAt);
        $this->assertSame(0, $state->consecutiveFailures);
    }

    public function test_round_trips_and_survives_a_fresh_instance(): void
    {
        $firedAt = new DateTimeImmutable('2024-01-01 06:00:00', new DateTimeZone('UTC'));

        (new FileScheduleStateStore($this->path))->put('nightly', (new ScheduleState())->firedAt($firedAt));

        // A fresh instance (simulating a restart) reads the persisted watermark.
        $reloaded = (new FileScheduleStateStore($this->path))->get('nightly');

        $this->assertNotNull($reloaded->lastFiredAt);
        $this->assertSame('2024-01-01T06:00:00+00:00', $reloaded->lastFiredAt->format(\DateTimeInterface::ATOM));
        $this->assertSame(0, $reloaded->consecutiveFailures);
    }

    public function test_failure_state_persists_backoff(): void
    {
        $retryAfter = new DateTimeImmutable('2024-01-01 06:05:00', new DateTimeZone('UTC'));
        (new FileScheduleStateStore($this->path))->put('nightly', (new ScheduleState())->failed($retryAfter));

        $reloaded = (new FileScheduleStateStore($this->path))->get('nightly');

        $this->assertSame(1, $reloaded->consecutiveFailures);
        $this->assertNotNull($reloaded->retryAfter);
        $this->assertSame('2024-01-01T06:05:00+00:00', $reloaded->retryAfter->format(\DateTimeInterface::ATOM));
    }

    public function test_distinct_schedules_are_independent(): void
    {
        $store = new FileScheduleStateStore($this->path);
        $store->put('a', (new ScheduleState())->firedAt(new DateTimeImmutable('2024-01-01 01:00:00')));
        $store->put('b', (new ScheduleState())->firedAt(new DateTimeImmutable('2024-01-02 02:00:00')));

        $this->assertNotNull($store->get('a')->lastFiredAt);
        $this->assertNotNull($store->get('b')->lastFiredAt);
        $this->assertNotSame(
            $store->get('a')->lastFiredAt->format('c'),
            $store->get('b')->lastFiredAt->format('c'),
        );
    }
}
