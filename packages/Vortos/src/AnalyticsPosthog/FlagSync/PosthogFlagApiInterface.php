<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

/**
 * The management-API surface the definition sync depends on.
 *
 * Exists so the reconciliation logic — which decides what to create, what to patch, and what
 * to leave alone — is testable without reaching PostHog. That logic is the part with the
 * interesting failure modes (never overwrite a hand-made flag, never abandon the run because
 * one definition was rejected), and it would otherwise be reachable only over the network.
 */
interface PosthogFlagApiInterface
{
    /** True when the project id and a personal API key are both present. */
    public function isConfigured(): bool;

    /**
     * @return array<string,array<string,mixed>> every remote flag, keyed by flag key
     * @throws PosthogApiException
     */
    public function listFlags(): array;

    /**
     * @param array<string,mixed> $payload
     * @return int the new flag's PostHog id
     * @throws PosthogApiException
     */
    public function createFlag(array $payload): int;

    /**
     * @param array<string,mixed> $payload
     * @throws PosthogApiException
     */
    public function updateFlag(int $id, array $payload): void;
}
