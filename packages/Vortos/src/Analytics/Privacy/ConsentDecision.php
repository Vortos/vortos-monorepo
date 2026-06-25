<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

/**
 * Only {@see Granted} lets an event/identity/group proceed to a driver. `Unknown`
 * is **not** treated as implicit consent — privacy-by-default means the absence of
 * a decision is the same as a denial.
 */
enum ConsentDecision: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Unknown = 'unknown';
}
