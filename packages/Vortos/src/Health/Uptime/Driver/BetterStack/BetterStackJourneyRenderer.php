<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Driver\BetterStack;

use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;

/** Pure: {@see MonitorDescriptor} → Better Stack multistep-monitor payload. */
final class BetterStackJourneyRenderer
{
    /** @return array<string, mixed> */
    public function render(MonitorDescriptor $descriptor): array
    {
        return [
            'monitor_type' => 'multistep',
            'pronounceable_name' => $descriptor->name,
            'check_frequency' => $descriptor->intervalSeconds,
            'regions' => $descriptor->regions,
            'request_timeout' => $descriptor->responseTimeSloMs !== null
                ? (int) ceil($descriptor->responseTimeSloMs / 1000)
                : 30,
            'steps' => array_map($this->renderStep(...), $descriptor->journey->steps),
        ];
    }

    /** @return array<string, mixed> */
    private function renderStep(JourneyStep $step): array
    {
        $rendered = [
            'method' => $step->method,
            'url' => $step->pathTemplate,
            'expected_status_code' => $step->expectStatus,
        ];

        if ($step->bodyContains !== null) {
            $rendered['required_keyword'] = $step->bodyContains;
        }

        if ($step->extractAs !== null) {
            $rendered['extract'] = ['as' => $step->extractAs, 'json_path' => $step->extractJsonPath];
        }

        return $rendered;
    }
}
