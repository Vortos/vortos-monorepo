<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Suppression;

/**
 * Governs what happens when a send is attempted to a suppressed address.
 *
 * throw  — throw SuppressionListException immediately; no email is sent
 * skip   — silently remove suppressed recipients and send to the remaining ones
 * ignore — proceed with all recipients regardless of suppression status
 */
enum OnSuppressed: string
{
    case Throw  = 'throw';
    case Skip   = 'skip';
    case Ignore = 'ignore';
}
