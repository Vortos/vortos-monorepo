<?php

declare(strict_types=1);

namespace Vortos\Observability\Dashboard;

enum DashboardPanelType: string
{
    case TimeSeries = 'timeseries';
    case Stat = 'stat';
    case Gauge = 'gauge';
    case Table = 'table';
}
