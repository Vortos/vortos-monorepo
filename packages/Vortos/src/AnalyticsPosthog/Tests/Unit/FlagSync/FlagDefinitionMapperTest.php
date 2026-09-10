<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit\FlagSync;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\AnalyticsPosthog\FlagSync\FlagDefinitionMapper;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagLifecycleState;

final class FlagDefinitionMapperTest extends TestCase
{
    public function test_maps_the_fields_posthog_shows_in_its_ui(): void
    {
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(
            name: 'payments.bank_transfer',
            description: 'Offline bank transfer rail',
            enabled: true,
        ));

        // The dot is translated away: PostHog rejects any key outside [A-Za-z0-9_-] with a
        // 400, so mirroring the Vortos name verbatim failed for every dotted flag — which
        // is most of them. See PosthogFlagKey.
        $this->assertSame('payments_bank_transfer', $payload['key']);
        $this->assertSame('Offline bank transfer rail', $payload['name'], "PostHog's `name` is the description, not the key");
        $this->assertTrue($payload['active']);
    }

    public function test_a_flag_without_a_description_falls_back_to_its_key(): void
    {
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(name: 'ops.kill_switch', description: ''));

        $this->assertSame('ops.kill_switch', $payload['name']);
    }

    public function test_release_conditions_are_always_inert(): void
    {
        // The safety property: Vortos decides who gets a flag. If anything ever did ask PostHog
        // to evaluate one of these, the honest answer is "off" — not an accidental launch to
        // everybody.
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(enabled: true));

        $this->assertSame([['properties' => [], 'rollout_percentage' => 0]], $payload['filters']['groups']);
        $this->assertSame('server', $payload['evaluation_runtime']);
    }

    public function test_every_mirrored_flag_is_tagged_so_it_can_be_told_apart(): void
    {
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(
            name: 'f',
            kind: FlagKind::Experiment,
            owner: 'payments',
        ));

        $this->assertContains(FlagDefinitionMapper::MANAGED_TAG, $payload['tags']);
        $this->assertContains('kind:experiment', $payload['tags']);
        $this->assertContains('owner:payments', $payload['tags']);
        $this->assertContains('project:default', $payload['tags']);
    }

    public function test_a_draft_flag_is_not_active_even_when_enabled(): void
    {
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(
            enabled: true,
            lifecycle: FlagLifecycleState::Draft,
        ));

        $this->assertFalse($payload['active']);
    }

    public function test_an_archived_flag_is_archived_in_posthog(): void
    {
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(
            enabled: true,
            lifecycle: FlagLifecycleState::Archived,
        ));

        $this->assertTrue($payload['archived']);
        $this->assertFalse($payload['active']);
    }

    public function test_variants_are_mirrored_so_experiments_can_break_down_by_variant(): void
    {
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(variants: ['control' => 50, 'blue' => 50]));

        $this->assertSame(
            [
                ['key' => 'control', 'name' => 'control', 'rollout_percentage' => 50],
                ['key' => 'blue', 'name' => 'blue', 'rollout_percentage' => 50],
            ],
            $payload['filters']['multivariate']['variants'],
        );
    }

    public function test_variant_weights_are_rescaled_to_total_exactly_one_hundred(): void
    {
        // PostHog rejects the whole payload otherwise, so a flag whose weights are expressed
        // as a ratio must not take the rest of the sync down with it.
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(variants: ['a' => 1, 'b' => 1, 'c' => 1]));

        $percentages = array_column($payload['filters']['multivariate']['variants'], 'rollout_percentage');

        $this->assertSame(100, array_sum($percentages));
        $this->assertSame([33, 33, 34], $percentages, 'the rounding remainder lands on the last variant');
    }

    public function test_a_boolean_flag_declares_no_variants(): void
    {
        $payload = (new FlagDefinitionMapper())->toPayload($this->flag(variants: null));

        $this->assertArrayNotHasKey('multivariate', $payload['filters']);
    }

    public function test_an_identical_remote_flag_is_not_drift(): void
    {
        $mapper = new FlagDefinitionMapper();
        $flag = $this->flag(name: 'f', description: 'd', enabled: true);

        $this->assertFalse($mapper->differs($flag, $mapper->toPayload($flag)));
    }

    public function test_tag_order_is_not_drift(): void
    {
        $mapper = new FlagDefinitionMapper();
        $flag = $this->flag(name: 'f', description: 'd');
        $remote = $mapper->toPayload($flag);
        $remote['tags'] = array_reverse($remote['tags']);

        $this->assertFalse($mapper->differs($flag, $remote), 'a reordered tag list would otherwise PATCH every flag on every run');
    }

    public function test_a_changed_enabled_state_is_drift(): void
    {
        $mapper = new FlagDefinitionMapper();
        $flag = $this->flag(name: 'f', description: 'd', enabled: true);
        $remote = $mapper->toPayload($flag);
        $remote['active'] = false;

        $this->assertTrue($mapper->differs($flag, $remote));
    }

    public function test_a_new_variant_is_drift(): void
    {
        $mapper = new FlagDefinitionMapper();
        $flag = $this->flag(name: 'f', description: 'd', variants: ['a' => 50, 'b' => 50]);
        $remote = $mapper->toPayload($this->flag(name: 'f', description: 'd', variants: ['a' => 100]));

        $this->assertTrue($mapper->differs($flag, $remote));
    }

    public function test_fields_posthog_owns_are_not_drift(): void
    {
        // A human improving a flag's dashboard link in PostHog must not be fought by the sync.
        $mapper = new FlagDefinitionMapper();
        $flag = $this->flag(name: 'f', description: 'd');
        $remote = $mapper->toPayload($flag);
        $remote['analytics_dashboards'] = [17];
        $remote['created_by'] = ['id' => 3];

        $this->assertFalse($mapper->differs($flag, $remote));
    }

    /** @param array<string,int>|null $variants */
    private function flag(
        string $name = 'flag',
        string $description = '',
        bool $enabled = false,
        ?array $variants = null,
        FlagKind $kind = FlagKind::Release,
        FlagLifecycleState $lifecycle = FlagLifecycleState::Active,
        ?string $owner = null,
    ): FeatureFlag {
        return new FeatureFlag(
            id: 'id-1',
            name: $name,
            description: $description,
            enabled: $enabled,
            rules: [],
            variants: $variants,
            createdAt: new DateTimeImmutable('2026-01-01'),
            updatedAt: new DateTimeImmutable('2026-01-01'),
            kind: $kind,
            lifecycle: $lifecycle,
            owner: $owner,
        );
    }
}
