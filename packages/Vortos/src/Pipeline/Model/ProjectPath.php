<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A path inside the project — and therefore inside the signed release image — naming a file or a
 * directory the deploy copies onto the host (RC-3).
 *
 * Deliberately narrow: relative, no `.`/`..` segments, no hidden segments, no shell metacharacters.
 * The value is interpolated into the deploy one-shot's command line and joined onto both the image
 * root and the host deploy dir, so anything that could escape either root, or name `.env.prod` or
 * `.git`, is refused where it is declared rather than discovered on a host.
 */
final readonly class ProjectPath
{
    public function __construct(
        public string $value,
    ) {
        if (\strlen($value) > 255
            || preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$#', $value) !== 1
            || preg_match('#(^|/)\.\.?(/|$)#', $value) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'Project paths must be relative, non-hidden, without traversal or shell metacharacters (e.g. "observability/collector/otel-collector-config.yaml"), got "%s".',
                $value,
            ));
        }
    }

    /** Whether $other is this path or lies inside it. */
    public function contains(self $other): bool
    {
        return $other->value === $this->value || str_starts_with($other->value, $this->value . '/');
    }
}
