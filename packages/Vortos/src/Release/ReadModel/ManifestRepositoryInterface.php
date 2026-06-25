<?php

declare(strict_types=1);

namespace Vortos\Release\ReadModel;

use Vortos\Release\Manifest\BuildManifest;

interface ManifestRepositoryInterface
{
    /**
     * Append-only: records a manifest immutably.
     *
     * @throws \Vortos\Release\Manifest\ManifestAlreadyExistsException on duplicate buildId
     */
    public function record(BuildManifest $manifest): void;
}
