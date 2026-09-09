<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagLifecycleState;

/**
 * Renders a Vortos {@see FeatureFlag} as a PostHog feature-flag definition.
 *
 * ## What this is for, and what it is emphatically not for
 *
 * PostHog only shows a flag in its Feature Flags UI — and only lets an Experiment be
 * attached to it — if a flag *entity* with that key exists in the project. Exposure events
 * alone create no entity, so without this mirror every flag in the product is invisible
 * there and no experiment can be built on one. Mirroring the definition fixes that.
 *
 * It does **not** hand evaluation to PostHog, and is written to make that impossible by
 * accident:
 *
 *  - Release conditions are always mirrored as a **0% rollout**. Vortos remains the only
 *    thing that decides who gets a flag; if some future client ever did ask PostHog to
 *    evaluate one of these, the honest answer it gets is "off" rather than an accidental
 *    launch to everybody.
 *  - `evaluation_runtime` is `server`, so PostHog's browser SDK will not treat these as
 *    client-evaluable.
 *  - Every mirrored flag is tagged `vortos`, so a human reading the PostHog UI can tell at
 *    a glance which flags are governed elsewhere — and so the sync can recognise its own
 *    flags without touching one a human created by hand.
 *
 * Variants are mirrored faithfully, because PostHog needs the variant keys to render
 * experiment results broken down by variant.
 */
final readonly class FlagDefinitionMapper
{
    /** Marks a flag as mirrored from Vortos rather than authored in PostHog. */
    public const MANAGED_TAG = 'vortos';

    /** PostHog validates that multivariate rollout percentages total exactly this. */
    private const VARIANT_TOTAL = 100;

    /** @return array<string,mixed> */
    public function toPayload(FeatureFlag $flag): array
    {
        $payload = [
            'key' => $flag->name,
            // PostHog's `name` is the human-readable description, not the key.
            'name' => $flag->description !== '' ? $flag->description : $flag->name,
            'active' => $flag->enabled && $flag->lifecycle === FlagLifecycleState::Active,
            'tags' => $this->tags($flag),
            'evaluation_runtime' => 'server',
            'filters' => $this->filters($flag),
        ];

        // An archived Vortos flag is archived in PostHog too, so the mirror does not become a
        // graveyard of flags the product deleted years ago.
        if ($flag->lifecycle === FlagLifecycleState::Archived) {
            $payload['archived'] = true;
        }

        return $payload;
    }

    /**
     * True when the remote flag differs from what we would send — the guard that keeps a
     * sync from PATCHing every flag on every run.
     *
     * Compares only the fields this mapper owns. Anything a human set in PostHog that we do
     * not mirror (a dashboard link, a description they improved) is intentionally not drift.
     *
     * @param array<string,mixed> $remote
     */
    public function differs(FeatureFlag $flag, array $remote): bool
    {
        $desired = $this->toPayload($flag);

        foreach (['key', 'name', 'active', 'evaluation_runtime'] as $field) {
            if (($remote[$field] ?? null) !== $desired[$field]) {
                return true;
            }
        }

        if ($this->normaliseTags($remote['tags'] ?? []) !== $this->normaliseTags($desired['tags'])) {
            return true;
        }

        if (($remote['archived'] ?? false) !== ($desired['archived'] ?? false)) {
            return true;
        }

        return $this->variantKeys($remote['filters'] ?? []) !== $this->variantKeys($desired['filters']);
    }

    /** @return list<string> */
    private function tags(FeatureFlag $flag): array
    {
        $tags = [
            self::MANAGED_TAG,
            'kind:' . $flag->kind->value,
            'project:' . $flag->projectId,
            'env:' . $flag->environment,
        ];

        if ($flag->owner !== null && $flag->owner !== '') {
            $tags[] = 'owner:' . $flag->owner;
        }

        return $tags;
    }

    /** @return array<string,mixed> */
    private function filters(FeatureFlag $flag): array
    {
        $filters = [
            // Inert by design — see the class docblock.
            'groups' => [['properties' => [], 'rollout_percentage' => 0]],
        ];

        $variants = $this->variants($flag);
        if ($variants !== []) {
            $filters['multivariate'] = ['variants' => $variants];
        }

        return $filters;
    }

    /**
     * Vortos stores variants as `name => weight`. PostHog requires the weights to total
     * exactly 100 and rejects the whole payload otherwise, so weights are rescaled and the
     * rounding remainder is absorbed by the last variant. A flag whose weights already total
     * 100 is therefore mirrored unchanged.
     *
     * @return list<array<string,mixed>>
     */
    private function variants(FeatureFlag $flag): array
    {
        $source = $flag->variants ?? [];
        if ($source === []) {
            return [];
        }

        $weights = [];
        foreach ($source as $key => $weight) {
            if ($key !== '' && $weight >= 0) {
                $weights[$key] = (float) $weight;
            }
        }

        if ($weights === []) {
            return [];
        }

        $total = array_sum($weights);
        $variants = [];
        $allocated = 0;
        $keys = array_keys($weights);
        $last = array_key_last($keys);

        foreach ($keys as $index => $key) {
            if ($index === $last) {
                // The remainder, so the total is exactly 100 regardless of rounding.
                $percentage = self::VARIANT_TOTAL - $allocated;
            } else {
                $percentage = $total > 0.0
                    ? (int) round($weights[$key] / $total * self::VARIANT_TOTAL)
                    : intdiv(self::VARIANT_TOTAL, count($keys));
                $allocated += $percentage;
            }

            $variants[] = [
                'key' => $key,
                'name' => $key,
                'rollout_percentage' => max(0, $percentage),
            ];
        }

        return $variants;
    }

    /**
     * `$tags` is whatever PostHog returned for that field, so it is genuinely unknown here.
     *
     * @return list<string>
     */
    private function normaliseTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $normalised = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $normalised[] = $tag;
            }
        }

        sort($normalised);

        return $normalised;
    }

    /**
     * The variant keys a filters blob declares, in order — enough to spot a variant added,
     * removed or renamed without treating a percentage tweak as drift.
     *
     * @return list<string>
     */
    private function variantKeys(mixed $filters): array
    {
        if (!is_array($filters)) {
            return [];
        }

        $variants = $filters['multivariate']['variants'] ?? [];
        if (!is_array($variants)) {
            return [];
        }

        $keys = [];
        foreach ($variants as $variant) {
            if (is_array($variant) && isset($variant['key']) && is_string($variant['key'])) {
                $keys[] = $variant['key'];
            }
        }

        return $keys;
    }
}
