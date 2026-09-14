<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A host env file that is deliberately NOT delivered by the pipeline, because it holds the identity every
 * sealed file is opened with (e.g. `age.env`). Sealing it would need itself to open.
 *
 * Declaring it is still required: the topology policy refuses any env file it cannot account for, so the
 * one file that legitimately lives only on the host is named, scoped to its services and justified,
 * rather than being the gap every other hand-made secret file slips through.
 */
final readonly class RootOfTrustEnvFile
{
    public function __construct(
        public HostEnvFileName $target,
        public ComposeServiceSet $services,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(sprintf(
                'Root-of-trust env file "%s" must state why it cannot be delivered sealed.',
                $target->value,
            ));
        }
    }
}
