<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Driver\BetterStack;

use Vortos\Health\Uptime\Exception\MonitorSyncException;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;

/**
 * {@see MonitorDescriptor} → a Better Stack monitor payload their API actually accepts.
 *
 * WHAT WAS WRONG BEFORE
 * ---------------------
 * This emitted `monitor_type: multistep` with a `steps` array, and the client added an
 * `external_id`. None of those are attributes of Better Stack's `/api/v2/monitors` resource, so
 * every sync failed:
 *
 *     HTTP 422 {"errors":"Sorry, you misspelled some attributes",
 *               "invalid_attributes":["steps","external_id"]}
 *
 * Because SyntheticJourney requires at least two steps by construction, there was no journey the
 * driver could successfully sync — the entire declarative uptime seam was unusable against the real
 * API, and the failure only appeared the first time anyone ran `health:monitor:sync --apply`.
 *
 * WHAT IT DOES NOW
 * ----------------
 * A monitor in that API checks ONE url. So the journey is projected onto the step that carries the
 * strongest assertion: the LAST step with a `bodyContains`. That choice is deliberate — a journey
 * is written so the final step proves the thing that matters (readiness reached, a real resource
 * returned), and asserting a body keyword catches a degraded-but-200 response that a bare status
 * check would pass.
 *
 * The earlier steps are not silently discarded: {@see self::describeCoverage()} records what the
 * monitor does and does not cover in the monitor's own name, so nobody reads the dashboard and
 * believes a multi-step journey is being exercised when it is not.
 */
final class BetterStackJourneyRenderer
{
    /** @return array<string, mixed> */
    public function render(MonitorDescriptor $descriptor): array
    {
        $step = $this->assertingStep($descriptor);

        $payload = [
            // `keyword` asserts the response BODY as well as the status. `status` would pass on any
            // 2xx, which is precisely the degraded case a health endpoint exists to reveal.
            'monitor_type' => 'keyword',
            'url' => $step->pathTemplate,
            'required_keyword' => $step->bodyContains,
            'pronounceable_name' => $this->describeCoverage($descriptor, $step),
            'check_frequency' => $descriptor->intervalSeconds,
            'http_method' => strtolower($step->method),
            'request_timeout' => $descriptor->responseTimeSloMs !== null
                ? max(1, (int) ceil($descriptor->responseTimeSloMs / 1000))
                : 30,
        ];

        if ($descriptor->regions !== []) {
            $payload['regions'] = $descriptor->regions;
        }

        return $payload;
    }

    /**
     * The last step that asserts a body invariant.
     *
     * SyntheticJourney guarantees at least one exists, so this cannot legitimately fail — but it
     * throws rather than silently degrading to a status-only monitor, because a monitor that
     * quietly checks less than it claims is worse than no monitor.
     */
    private function assertingStep(MonitorDescriptor $descriptor): JourneyStep
    {
        foreach (array_reverse($descriptor->journey->steps) as $step) {
            if ($step->assertsBodyInvariant()) {
                return $step;
            }
        }

        throw MonitorSyncException::forFailure(
            $descriptor->key,
            'journey has no step asserting a body invariant, so it cannot be projected onto a '
            . 'keyword monitor without weakening it to a status-only check',
        );
    }

    /**
     * Names the monitor after what it genuinely verifies, including the steps it cannot.
     *
     * The API has no field for "this is one leg of a longer journey", so the name carries it. A
     * dashboard entry that reads "Sqoura API (1 of 2 steps: /health/ready)" is honest; one that
     * reads "Sqoura API — full journey" while checking a single URL is not.
     */
    private function describeCoverage(MonitorDescriptor $descriptor, JourneyStep $asserting): string
    {
        $total = \count($descriptor->journey->steps);

        if ($total === 1) {
            return $descriptor->name;
        }

        return sprintf('%s (1 of %d steps: %s)', $descriptor->name, $total, $asserting->pathTemplate);
    }
}
