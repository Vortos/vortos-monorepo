<?php

declare(strict_types=1);

namespace Vortos\Health\Monitor;

use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;

/** Deterministic content hash of a {@see MonitorDescriptor} — the idempotency key for sync (§6.4). */
final class MonitorDescriptorHasher
{
    public function hash(MonitorDescriptor $descriptor): string
    {
        return hash('sha256', json_encode($this->canonical($descriptor), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function canonical(MonitorDescriptor $descriptor): array
    {
        return [
            'key' => $descriptor->key,
            'name' => $descriptor->name,
            'intervalSeconds' => $descriptor->intervalSeconds,
            'regions' => $descriptor->regions,
            'responseTimeSloMs' => $descriptor->responseTimeSloMs,
            'journey' => [
                'name' => $descriptor->journey->name,
                'steps' => array_map($this->canonicalStep(...), $descriptor->journey->steps),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function canonicalStep(JourneyStep $step): array
    {
        return [
            'method' => $step->method,
            'pathTemplate' => $step->pathTemplate,
            'expectStatus' => $step->expectStatus,
            'bodyContains' => $step->bodyContains,
            'extractAs' => $step->extractAs,
            'extractJsonPath' => $step->extractJsonPath,
        ];
    }
}
