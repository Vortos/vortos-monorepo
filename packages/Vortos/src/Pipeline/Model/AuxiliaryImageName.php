<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * The name of an auxiliary image (RC-9): a lowercase slug that becomes both the sibling tag suffix
 * (`:sha-<sha>-<name>`) and the compose interpolation variable the deploy writes (`VORTOS_IMAGE_<NAME>`).
 *
 * Narrow on purpose: it is interpolated into workflow step ids, image tags and the deploy script, and
 * `ops` is taken by the deploy-ops sibling.
 */
final readonly class AuxiliaryImageName
{
    public function __construct(
        public string $value,
    ) {
        if (preg_match('/^[a-z][a-z0-9]{1,23}$/', $value) !== 1 || $value === 'ops') {
            throw new \InvalidArgumentException(sprintf(
                'Auxiliary image names must be 2-24 lowercase letters/digits starting with a letter, and not "ops", got "%s".',
                $value,
            ));
        }
    }

    /** The compose interpolation variable holding this image's digest reference. */
    public function composeVariable(): string
    {
        return 'VORTOS_IMAGE_' . strtoupper($this->value);
    }

    /** The build-stage output (and step id suffix) carrying this image's digest. */
    public function outputName(): string
    {
        return 'aux' . $this->value;
    }
}
