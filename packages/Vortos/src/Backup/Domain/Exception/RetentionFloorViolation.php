<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain\Exception;

/**
 * Raised if a retention apply is ever asked to delete a backup the policy itself
 * marked as refused (the most-recent / floor-protected copy).
 *
 * This is a belt-and-braces guard: the {@see \Vortos\Backup\Domain\RetentionPolicy}
 * never places a floor-protected artifact in the delete set, so reaching this means
 * a caller hand-built an unsafe plan — which must fail closed, never delete the only
 * good copy.
 */
final class RetentionFloorViolation extends BackupException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            "Refusing to delete '%s': it is the floor-protected (most recent) backup. "
            . 'Deleting it could leave no restorable copy.',
            $key,
        ));
    }
}
