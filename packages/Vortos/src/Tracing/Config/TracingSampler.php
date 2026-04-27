<?php
declare(strict_types=1);

namespace Vortos\Tracing\Config;

enum TracingSampler
{
    case AlwaysOn;
    case AlwaysOff;
    case Ratio;
}
