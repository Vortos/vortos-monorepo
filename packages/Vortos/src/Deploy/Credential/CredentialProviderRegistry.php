<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class CredentialProviderRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('deploy-credential', $drivers);
    }

    public function provider(string $key): CredentialProviderInterface
    {
        /** @var CredentialProviderInterface */
        return $this->get($key);
    }
}
