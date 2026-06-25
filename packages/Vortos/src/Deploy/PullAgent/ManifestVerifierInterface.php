<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

use Vortos\Deploy\Exception\ManifestSignatureInvalidException;
use Vortos\Deploy\Exception\UnsignedManifestException;

interface ManifestVerifierInterface
{
    /**
     * @throws UnsignedManifestException
     * @throws ManifestSignatureInvalidException
     */
    public function verify(SignedDesiredStateManifest $signed): void;
}
