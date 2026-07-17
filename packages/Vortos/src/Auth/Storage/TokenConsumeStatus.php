<?php

declare(strict_types=1);

namespace Vortos\Auth\Storage;

/**
 * The three distinguishable outcomes of consuming a refresh token JTI.
 *
 * @see TokenConsumeResult
 */
enum TokenConsumeStatus: string
{
    /** JTI was live (or within rotation grace) — rotate normally. */
    case Rotated = 'rotated';

    /** JTI was deliberately revoked — reject this token only, keep other sessions. */
    case Revoked = 'revoked';

    /** JTI already consumed and never revoked — treat as theft (revoke all). */
    case Reused = 'reused';
}
