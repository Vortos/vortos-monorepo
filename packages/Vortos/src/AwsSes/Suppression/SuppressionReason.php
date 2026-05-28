<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Suppression;

enum SuppressionReason: string
{
    case Bounce    = 'bounce';
    case Complaint = 'complaint';
    case Manual    = 'manual';
}
