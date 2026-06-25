<?php

declare(strict_types=1);

namespace Vortos\Deploy\Compose;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Target\ActiveColor;

final readonly class ComposeFile
{
    /**
     * @param array<string, string> $appEnvironment
     * @param array<string, string> $workerEnvironment
     * @param list<string>          $networks
     */
    public function __construct(
        public string $projectName,
        public ActiveColor $color,
        public ImageReference $image,
        public string $appCommand,
        public string $workerCommand,
        public int $appPort,
        public array $appEnvironment = [],
        public array $workerEnvironment = [],
        public array $networks = ['vortos-net'],
        public ?string $envFile = null,
    ) {
        if (!$image->isDigestPinned()) {
            throw new \InvalidArgumentException('Compose file image must be digest-pinned.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $imageRef = $this->image->toString();

        $appService = [
            'image' => $imageRef,
            'command' => $this->appCommand,
            'ports' => [sprintf('%d:8080', $this->appPort)],
            'networks' => $this->networks,
            'restart' => 'unless-stopped',
        ];

        $workerService = [
            'image' => $imageRef,
            'command' => $this->workerCommand,
            'networks' => $this->networks,
            'restart' => 'unless-stopped',
        ];

        if ($this->envFile !== null) {
            $appService['env_file'] = [$this->envFile];
            $workerService['env_file'] = [$this->envFile];
        }

        if ($this->appEnvironment !== []) {
            $appService['environment'] = $this->appEnvironment;
        }

        if ($this->workerEnvironment !== []) {
            $workerService['environment'] = $this->workerEnvironment;
        }

        return [
            'services' => [
                sprintf('app-%s', $this->color->value) => $appService,
                sprintf('worker-%s', $this->color->value) => $workerService,
            ],
            'networks' => array_fill_keys($this->networks, ['external' => true]),
        ];
    }
}
