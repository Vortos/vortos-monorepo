<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\Migrations\DependencyFactory;

interface DependencyFactoryProviderInterface
{
    public function create(): DependencyFactory;
}
