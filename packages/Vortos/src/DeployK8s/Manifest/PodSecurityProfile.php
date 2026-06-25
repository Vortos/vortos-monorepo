<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Manifest;

final class PodSecurityProfile
{
    /** @return array<string, mixed> */
    public function restricted(int $runAsUser = 1000): array
    {
        return [
            'runAsNonRoot' => true,
            'runAsUser' => $runAsUser,
            'allowPrivilegeEscalation' => false,
            'readOnlyRootFilesystem' => true,
            'capabilities' => [
                'drop' => ['ALL'],
            ],
            'seccompProfile' => [
                'type' => 'RuntimeDefault',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function podSecurityContext(int $runAsUser = 1000, int $fsGroup = 1000): array
    {
        return [
            'runAsNonRoot' => true,
            'runAsUser' => $runAsUser,
            'fsGroup' => $fsGroup,
            'seccompProfile' => [
                'type' => 'RuntimeDefault',
            ],
        ];
    }
}
