<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

use Vortos\FeatureFlags\FlagContext;

/**
 * Derives group associations (org / account / team) for an exposure from the *trusted*
 * zone of a {@see FlagContext}.
 *
 * Trusted-only by construction: the map is only ever read through
 * {@see FlagContext::getTrusted()}, never the merged accessor. A client that spoofs
 * `X-Vortos-Flag-Context` therefore cannot attribute its exposures to another tenant —
 * which matters, because these group keys end up as the grouping dimension in analytics
 * and a spoofable one would let any user pollute another org's rollout numbers.
 *
 * The map is `group type => trusted context key`, defaulting to the one association
 * every tenanted app has. Group *type* names are the analytics backend's vocabulary,
 * so they are configuration here rather than a hardcoded literal.
 */
final readonly class ExposureGroupResolver
{
    public const DEFAULT_MAP = ['organization' => 'tenantId'];

    /** @param array<string,string> $groupMap group type => trusted context key */
    public function __construct(private array $groupMap = self::DEFAULT_MAP) {}

    /** @return array<string,string> group type => group key; absent/blank associations are omitted */
    public function resolve(FlagContext $context): array
    {
        $groups = [];

        foreach ($this->groupMap as $groupType => $trustedKey) {
            if ($groupType === '') {
                continue;
            }

            $value = $context->getTrusted($trustedKey);

            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $key = (string) $value;
            if ($key !== '') {
                $groups[$groupType] = $key;
            }
        }

        return $groups;
    }
}
