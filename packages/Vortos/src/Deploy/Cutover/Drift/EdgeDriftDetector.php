<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Drift;

use Vortos\Deploy\Cutover\Edge\BootConfigReaderInterface;
use Vortos\Deploy\Cutover\Edge\LiveConfigReaderInterface;
use Vortos\Deploy\Cutover\Edge\MergeOutcome;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;

/**
 * Detects drift between the recorded edge routing intent and what the edge is ACTUALLY serving.
 *
 * The incident that motivated this whole feature was drift-driven: the live route existed only in the
 * admin API's memory, so a restart reverted it. This detector is the standing guard against the
 * general class of that problem — a manual admin push, a stale boot file, or an adapt-version skew
 * that leaves the live edge out of step with the intent the framework recorded.
 *
 * It is READ-ONLY: it reads the durable state, the live admin config, and the on-disk boot file, and
 * compares. It writes nothing and emits nothing — surfacing (a doctor Fail) and alerting (via the
 * cutover event recorder) are the callers' jobs, so this can be reused by a preflight check without
 * violating the read-only contract.
 */
final class EdgeDriftDetector
{
    public function __construct(
        private readonly EdgeStateStoreInterface $stateStore,
        private readonly LiveConfigReaderInterface $liveConfig,
        private readonly ?BootConfigReaderInterface $bootConfigReader = null,
    ) {}

    public function detect(string $env): EdgeDriftReport
    {
        $state = $this->stateStore->load($env);
        if ($state === null) {
            return EdgeDriftReport::noState();
        }

        $expectedDial = sprintf('%s:%d', $state->upstreamHost, $state->upstreamPort);
        $reasons = [];

        // 1) Live upstream vs recorded active color.
        try {
            $live = $this->liveConfig->currentConfig();
        } catch (\Throwable $e) {
            return EdgeDriftReport::drifted(
                ['edge admin API unreachable — cannot confirm the live route: ' . $e->getMessage()],
                $expectedDial,
            );
        }

        if ($live === []) {
            $reasons[] = 'live edge has no config loaded (expected ' . $expectedDial . ')';
        } elseif (!$this->configContainsDial($live, $expectedDial)) {
            $reasons[] = 'live upstream does not match the recorded active color (expected ' . $expectedDial . ')';
        }

        // 2) On-disk boot file vs the recorded config hash (so a cold restart self-heals to intent).
        if ($this->bootConfigReader !== null && $state->configHash !== null) {
            $bootHash = $this->bootFileHash();
            if ($bootHash === null) {
                $reasons[] = 'edge boot file is missing or unreadable (a restart would not self-heal to intent)';
            } elseif ($bootHash !== $state->configHash) {
                $reasons[] = 'edge boot file does not match the recorded config (stale file or manual edit)';
            }
        }

        return $reasons === []
            ? EdgeDriftReport::inSync($expectedDial)
            : EdgeDriftReport::drifted($reasons, $expectedDial);
    }

    private function bootFileHash(): ?string
    {
        $json = $this->bootConfigReader?->read();
        if ($json === null || $json === '') {
            return null;
        }

        try {
            // Decode with empty-object preservation (NOT JSON_OBJECT_AS_ARRAY) so this hash matches the
            // one recorded at cutover — canonicalize() distinguishes {} from [], so an empty-object
            // handler (e.g. encode-gzip) must not round-trip to [] here. See MergeOutcome::decode.
            $decoded = MergeOutcome::decode($json);
        } catch (\JsonException) {
            return null;
        }

        return hash('sha256', MergeOutcome::canonicalize($decoded));
    }

    /** @param array<string, mixed> $config */
    private function configContainsDial(array $config, string $dial): bool
    {
        $json = json_encode($config, \JSON_THROW_ON_ERROR);

        return str_contains($json, '"dial":"' . $dial . '"')
            || str_contains($json, '"dial": "' . $dial . '"');
    }
}
