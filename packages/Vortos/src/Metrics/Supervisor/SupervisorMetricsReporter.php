<?php

declare(strict_types=1);

namespace Vortos\Metrics\Supervisor;

use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Turns supervisord program state into metrics, including the restart counter supervisor itself
 * does not expose.
 *
 * Stateful by necessity: supervisor reports a program's *current* pid and uptime, never how many
 * times it has been respawned. A crash-looping consumer therefore looks identical to a healthy one
 * in any single sample — same RUNNING state, just a small uptime. Restarts are derived here by
 * remembering the previously observed pid per program, which is why this must be driven by the
 * long-running {@see \Vortos\Metrics\Command\SupervisorMetricsCommand} rather than a one-shot sweep.
 *
 * A null telemetry (metrics adapter absent) makes every method a no-op.
 */
final class SupervisorMetricsReporter
{
    /** @var array<string, int|null> Last observed pid per program. */
    private array $lastPids = [];

    /** @var array<string, true> Programs seen at least once, so a first observation is not a restart. */
    private array $seen = [];

    public function __construct(
        private readonly ?FrameworkTelemetry $telemetry = null,
        private readonly string $procDirectory = '/proc',
    ) {}

    /**
     * @param list<SupervisorProcessStatus> $statuses
     */
    public function report(array $statuses): void
    {
        foreach ($statuses as $status) {
            $labels = FrameworkMetricLabels::of(
                MetricLabelValue::of(MetricLabel::Program, $status->program),
            );

            $this->telemetry?->setGauge(
                ObservabilityModule::Deploy,
                FrameworkMetric::SupervisorProgramUp,
                $labels,
                $status->isRunning() ? 1.0 : 0.0,
            );

            if ($status->uptimeSeconds !== null) {
                $this->telemetry?->setGauge(
                    ObservabilityModule::Deploy,
                    FrameworkMetric::SupervisorProgramUptimeSeconds,
                    $labels,
                    (float) $status->uptimeSeconds,
                );
            }

            $this->reportRestarts($status, $labels);
            $this->reportMemory($status, $labels);
        }
    }

    private function reportRestarts(SupervisorProcessStatus $status, FrameworkMetricLabels $labels): void
    {
        $previousPid = $this->lastPids[$status->program] ?? null;
        $firstObservation = !isset($this->seen[$status->program]);

        $this->seen[$status->program] = true;
        $this->lastPids[$status->program] = $status->pid;

        // The first sample establishes a baseline. Counting it would report a restart for every
        // program every time this collector itself starts — including on every deploy.
        if ($firstObservation) {
            return;
        }

        // A new pid means supervisor respawned the program. Covers both a straight crash-restart
        // (pid A to pid B) and a recovery from a down state (null to pid B); a program that simply
        // stays down is already visible as up=0 and is not counted again here.
        if ($status->pid === null || $status->pid === $previousPid) {
            return;
        }

        $this->telemetry?->increment(
            ObservabilityModule::Deploy,
            FrameworkMetric::SupervisorProgramRestartsTotal,
            $labels,
        );
    }

    /**
     * Reads RSS from /proc/<pid>/status rather than deriving it from statm's page count. VmRSS is
     * reported in kB directly, which sidesteps page size entirely — aarch64 hosts can run 4K, 16K
     * or 64K pages, so assuming 4096 would silently misreport memory by up to 16x.
     */
    private function reportMemory(SupervisorProcessStatus $status, FrameworkMetricLabels $labels): void
    {
        if ($status->pid === null) {
            return;
        }

        $path = $this->procDirectory . '/' . $status->pid . '/status';

        if (!is_readable($path)) {
            return;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || preg_match('/^VmRSS:\s+(\d+) kB$/m', $contents, $matches) !== 1) {
            return;
        }

        $this->telemetry?->setGauge(
            ObservabilityModule::Deploy,
            FrameworkMetric::SupervisorProgramMemoryBytes,
            $labels,
            (float) ((int) $matches[1] * 1024),
        );
    }
}
