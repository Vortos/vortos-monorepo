<?php

declare(strict_types=1);

namespace Vortos\Observability\Dashboard;

/** A titled row of panels — Grafana renders each as a collapsible section. */
final readonly class DashboardSection
{
    /** @param list<DashboardPanel> $panels */
    public function __construct(
        public string $title,
        public array $panels,
    ) {}
}
