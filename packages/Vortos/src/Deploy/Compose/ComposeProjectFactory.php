<?php

declare(strict_types=1);

namespace Vortos\Deploy\Compose;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Target\ActiveColor;

final class ComposeProjectFactory
{
    private const DEFAULT_APP_COMMAND = 'php-server';
    private const DEFAULT_WORKER_COMMAND = 'php bin/console messenger:consume --time-limit=3600';
    private const DEFAULT_APP_PORT_BLUE = 8081;
    private const DEFAULT_APP_PORT_GREEN = 8082;

    public function create(
        ActiveColor $color,
        ImageReference $image,
        ?string $envFile = null,
        string $appCommand = self::DEFAULT_APP_COMMAND,
        string $workerCommand = self::DEFAULT_WORKER_COMMAND,
    ): ComposeFile {
        $port = $color === ActiveColor::Blue ? self::DEFAULT_APP_PORT_BLUE : self::DEFAULT_APP_PORT_GREEN;

        return new ComposeFile(
            projectName: sprintf('vortos-app-%s', $color->value),
            color: $color,
            image: $image,
            appCommand: $appCommand,
            workerCommand: $workerCommand,
            appPort: $port,
            envFile: $envFile,
        );
    }

    public function endpointFor(ActiveColor $color): ColorEndpoint
    {
        $port = $color === ActiveColor::Blue ? self::DEFAULT_APP_PORT_BLUE : self::DEFAULT_APP_PORT_GREEN;

        return new ColorEndpoint(
            host: sprintf('app-%s', $color->value),
            port: $port,
        );
    }
}
