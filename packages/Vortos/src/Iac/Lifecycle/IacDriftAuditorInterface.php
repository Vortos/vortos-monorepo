<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

interface IacDriftAuditorInterface
{
    public function audit(string $environment): IacDriftReport;
}
