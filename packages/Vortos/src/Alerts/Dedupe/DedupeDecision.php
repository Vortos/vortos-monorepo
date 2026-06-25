<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

enum DedupeDecision: string
{
    /** First occurrence (or the dedupe window expired) — notify normally. */
    case New = 'new';
    /** Inside the window, collapsed into the running count — no outbound notification. */
    case Deduped = 'deduped';
    /** Inside the window but the digest threshold was crossed — send a "still firing" summary. */
    case Digest = 'digest';
}
