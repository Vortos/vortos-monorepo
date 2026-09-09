<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit\FlagSync;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\AnalyticsPosthog\FlagSync\FlagDefinitionMapper;
use Vortos\AnalyticsPosthog\FlagSync\PosthogApiException;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagApiInterface;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSynchronizer;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class PosthogFlagSynchronizerTest extends TestCase
{
    public function test_a_flag_missing_from_posthog_is_created(): void
    {
        $api = $this->api();
        $report = $this->sync(['checkout'], $api)->syncAll();

        $this->assertSame(['checkout'], $report->created);
        $this->assertSame(['checkout'], array_column($api->created, 'key'));
    }

    public function test_an_unchanged_flag_is_left_alone(): void
    {
        // A steady-state run must cost one list call and no writes, or every reconciliation
        // rewrites the whole project and buries real changes in PostHog's activity log.
        $mapper = new FlagDefinitionMapper();
        $flag = $this->flag('checkout');
        $api = $this->api(['checkout' => ['id' => 1] + $mapper->toPayload($flag)]);

        $report = new PosthogFlagSynchronizer($this->storage([$flag]), $api, $mapper)->syncAll();

        $this->assertSame(['checkout'], $report->unchanged);
        $this->assertSame([], $api->updated);
    }

    public function test_a_drifted_flag_is_patched(): void
    {
        $mapper = new FlagDefinitionMapper();
        $flag = $this->flag('checkout', enabled: true);
        $remote = ['id' => 7] + $mapper->toPayload($flag);
        $remote['active'] = false;

        $api = $this->api(['checkout' => $remote]);
        $report = new PosthogFlagSynchronizer($this->storage([$flag]), $api, $mapper)->syncAll();

        $this->assertSame(['checkout'], $report->updated);
        $this->assertSame([7], array_keys($api->updated));
        $this->assertTrue($api->updated[7]['active']);
    }

    public function test_a_hand_made_posthog_flag_is_never_overwritten(): void
    {
        // Silently replacing someone's hand-configured flag with an inert 0% mirror is a far
        // worse failure than refusing to touch it.
        $api = $this->api(['checkout' => ['id' => 9, 'key' => 'checkout', 'tags' => [], 'active' => true]]);

        $report = $this->sync(['checkout'], $api)->syncAll();

        $this->assertSame([], $api->updated);
        $this->assertArrayHasKey('checkout', $report->failed);
        $this->assertStringContainsString('not overwriting', $report->failed['checkout']);
    }

    public function test_a_flag_only_posthog_knows_about_is_never_deleted(): void
    {
        $api = $this->api(['someone-elses-flag' => ['id' => 4, 'key' => 'someone-elses-flag', 'tags' => ['vortos']]]);

        $this->sync(['checkout'], $api)->syncAll();

        $this->assertSame([], $api->deleted, 'the sync is additive; it owns creation and drift, never deletion');
    }

    public function test_one_rejected_flag_does_not_abandon_the_rest(): void
    {
        $api = new class extends FakeFlagApi {
            public function createFlag(array $payload): int
            {
                if ($payload['key'] === 'bad') {
                    throw new PosthogApiException('rejected', 400, '{"detail":"nope"}');
                }

                return parent::createFlag($payload);
            }
        };

        $report = $this->sync(['aaa', 'bad', 'zzz'], $api)->syncAll();

        $this->assertSame(['aaa', 'zzz'], $report->created);
        $this->assertArrayHasKey('bad', $report->failed);
        $this->assertTrue($report->hasFailures());
    }

    public function test_a_dry_run_writes_nothing_but_reports_everything(): void
    {
        $api = $this->api();
        $report = $this->sync(['checkout'], $api)->syncAll(dryRun: true);

        $this->assertSame(['checkout'], $report->created);
        $this->assertSame([], $api->created);
        $this->assertTrue($report->dryRun);
    }

    public function test_a_failed_list_call_aborts_rather_than_posting_duplicates(): void
    {
        // Without the remote list we cannot tell "missing" from "already there", and guessing
        // means creating a second copy of every flag.
        $api = new class extends FakeFlagApi {
            public function listFlags(): array { throw new PosthogApiException('unreachable'); }
        };

        $this->expectException(PosthogApiException::class);

        $this->sync(['checkout'], $api)->syncAll();
    }

    public function test_sync_one_touches_only_that_flag(): void
    {
        $api = $this->api();
        $report = $this->sync(['a', 'b', 'c'], $api)->syncAll(dryRun: true);
        $this->assertCount(3, $report->created);

        $report = $this->sync(['a', 'b', 'c'], $api)->syncOne('b');

        $this->assertSame(['b'], $report->created);
    }

    public function test_syncing_an_unknown_flag_is_a_no_op_not_an_error(): void
    {
        $api = $this->api();

        $report = $this->sync(['a'], $api)->syncOne('does-not-exist');

        $this->assertSame(0, $report->total());
        $this->assertFalse($report->hasFailures());
    }

    public function test_no_flags_means_no_api_call_at_all(): void
    {
        $api = $this->api();

        $report = $this->sync([], $api)->syncAll();

        $this->assertSame(0, $report->total());
        $this->assertSame(0, $api->listCalls);
    }

    /** @param list<string> $flagNames */
    private function sync(array $flagNames, PosthogFlagApiInterface $api): PosthogFlagSynchronizer
    {
        $flags = array_map(fn (string $name): FeatureFlag => $this->flag($name), $flagNames);

        return new PosthogFlagSynchronizer($this->storage($flags), $api, new FlagDefinitionMapper());
    }

    /** @param array<string,array<string,mixed>> $remote */
    private function api(array $remote = []): FakeFlagApi
    {
        $api = new FakeFlagApi();
        $api->remote = $remote;

        return $api;
    }

    /** @param list<FeatureFlag> $flags */
    private function storage(array $flags): FlagStorageInterface
    {
        return new class($flags) implements FlagStorageInterface {
            /** @param list<FeatureFlag> $flags */
            public function __construct(private array $flags) {}

            public function findAll(): array { return $this->flags; }

            public function findByName(string $name): ?FeatureFlag
            {
                foreach ($this->flags as $flag) {
                    if ($flag->name === $name) {
                        return $flag;
                    }
                }

                return null;
            }

            public function save(FeatureFlag $flag): void {}
            public function delete(string $name): void {}
        };
    }

    private function flag(string $name, bool $enabled = false): FeatureFlag
    {
        return new FeatureFlag(
            id: 'id-' . $name,
            name: $name,
            description: 'desc ' . $name,
            enabled: $enabled,
            rules: [],
            variants: null,
            createdAt: new DateTimeImmutable('2026-01-01'),
            updatedAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}

/** Records what the sync would send, so reconciliation can be tested without a network. */
class FakeFlagApi implements PosthogFlagApiInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $remote = [];

    /** @var list<array<string,mixed>> */
    public array $created = [];

    /** @var array<int,array<string,mixed>> */
    public array $updated = [];

    /** @var list<int> */
    public array $deleted = [];

    public int $listCalls = 0;

    private int $nextId = 100;

    public function isConfigured(): bool { return true; }

    public function listFlags(): array
    {
        $this->listCalls++;

        return $this->remote;
    }

    public function createFlag(array $payload): int
    {
        $this->created[] = $payload;

        return $this->nextId++;
    }

    public function updateFlag(int $id, array $payload): void
    {
        $this->updated[$id] = $payload;
    }
}
