<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * What the merger did to land the live upstream in the operator's base config.
 *
 *  - Patched:  the operator wrote a reverse_proxy dialing app-<color>; the framework replaced only its
 *              upstreams field (plus load-balancing weights for a canary), preserving all its other
 *              settings.
 *  - Inserted: the operator wrote a site block with no app proxy; the framework inserted one carrying
 *              the live upstream.
 *
 * The ambiguous (>=2 app proxies) and unclear-zero cases never reach a MergeAction — they fail closed
 * as {@see \Vortos\Deploy\Exception\EdgeBaseConfigException} before a merge is attempted.
 */
enum MergeAction: string
{
    case Patched = 'patched';
    case Inserted = 'inserted';
}
