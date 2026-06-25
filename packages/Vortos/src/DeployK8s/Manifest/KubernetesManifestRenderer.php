<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Manifest;

use Vortos\DeployK8s\Api\KubeObject;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class KubernetesManifestRenderer
{
    public function __construct(
        private readonly PodSecurityProfile $security,
        private readonly RbacRenderer $rbac,
    ) {}

    /**
     * @param string $color 'blue' or 'green'
     * @return list<KubeObject>
     */
    public function render(WorkerProcessRegistry $workers, RenderContext $ctx, string $color = 'blue'): array
    {
        $objects = [];

        $objects[] = $this->renderAppDeployment($ctx, $color);
        $objects[] = $this->renderService($ctx);
        $objects[] = $this->renderHpa($ctx, $ctx->appName . '-' . $color);
        $objects[] = $this->renderPdb($ctx, $ctx->appName . '-' . $color);

        foreach ($workers->all() as $worker) {
            $objects[] = $this->renderWorkerDeployment($worker, $ctx);
            if ($worker->numprocs > 1) {
                $objects[] = $this->renderHpa($ctx, 'worker-' . $worker->name, $worker->numprocs, max($worker->numprocs * 3, 10));
            }
        }

        $objects = array_merge($objects, $this->rbac->render($ctx));

        return $objects;
    }

    private function renderAppDeployment(RenderContext $ctx, string $color): KubeObject
    {
        $name = $ctx->appName . '-' . $color;
        $labels = array_merge($ctx->labels, [
            'app.kubernetes.io/name' => $ctx->appName,
            'app.kubernetes.io/color' => $color,
            'app.kubernetes.io/managed-by' => 'vortos-deploy',
        ]);

        $container = [
            'name' => $ctx->appName,
            'image' => $ctx->imageReference,
            'ports' => [
                ['containerPort' => $ctx->port, 'protocol' => 'TCP'],
            ],
            'readinessProbe' => [
                'httpGet' => ['path' => $ctx->healthPath, 'port' => $ctx->port],
                'initialDelaySeconds' => 5,
                'periodSeconds' => 5,
            ],
            'livenessProbe' => [
                'httpGet' => ['path' => $ctx->livePath, 'port' => $ctx->port],
                'initialDelaySeconds' => 10,
                'periodSeconds' => 10,
            ],
            'securityContext' => $this->security->restricted(),
            'resources' => [
                'requests' => ['cpu' => '100m', 'memory' => '128Mi'],
                'limits' => ['memory' => '512Mi'],
            ],
        ];

        if ($ctx->secretRefs !== []) {
            $container['envFrom'] = array_map(
                fn (string $secretName) => ['secretRef' => ['name' => $secretName]],
                array_values($ctx->secretRefs),
            );
        }

        if ($ctx->envVars !== []) {
            $container['env'] = [];
            foreach ($ctx->envVars as $envName => $envValue) {
                if (str_starts_with($envValue, 'secretKeyRef:')) {
                    $parts = explode(':', $envValue, 3);
                    $container['env'][] = [
                        'name' => $envName,
                        'valueFrom' => ['secretKeyRef' => ['name' => $parts[1], 'key' => $parts[2] ?? $envName]],
                    ];
                } else {
                    $container['env'][] = ['name' => $envName, 'value' => $envValue];
                }
            }
        }

        $spec = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'namespace' => $ctx->namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'replicas' => max(2, $ctx->replicas),
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => $ctx->appName, 'app.kubernetes.io/color' => $color]],
                'strategy' => [
                    'type' => 'RollingUpdate',
                    'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1],
                ],
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'serviceAccountName' => $ctx->serviceAccountName,
                        'terminationGracePeriodSeconds' => 30,
                        'securityContext' => $this->security->podSecurityContext(),
                        'containers' => [$container],
                        'topologySpreadConstraints' => [
                            [
                                'maxSkew' => 1,
                                'topologyKey' => 'kubernetes.io/hostname',
                                'whenUnsatisfiable' => 'DoNotSchedule',
                                'labelSelector' => ['matchLabels' => ['app.kubernetes.io/name' => $ctx->appName, 'app.kubernetes.io/color' => $color]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return new KubeObject('Deployment', $name, $ctx->namespace, $spec);
    }

    private function renderWorkerDeployment(WorkerProcessDefinition $worker, RenderContext $ctx): KubeObject
    {
        $name = 'worker-' . $worker->name;
        $labels = array_merge($ctx->labels, [
            'app.kubernetes.io/name' => $name,
            'app.kubernetes.io/component' => 'worker',
            'app.kubernetes.io/managed-by' => 'vortos-deploy',
        ]);

        $grace = max($worker->stopwaitsecs, $worker->drainDeadline);

        $container = [
            'name' => $worker->name,
            'image' => $ctx->imageReference,
            'args' => explode(' ', $worker->command),
            'securityContext' => $this->security->restricted(),
            'resources' => [
                'requests' => ['cpu' => '50m', 'memory' => '64Mi'],
                'limits' => ['memory' => '256Mi'],
            ],
        ];

        if ($ctx->secretRefs !== []) {
            $container['envFrom'] = array_map(
                fn (string $secretName) => ['secretRef' => ['name' => $secretName]],
                array_values($ctx->secretRefs),
            );
        }

        $spec = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $name,
                'namespace' => $ctx->namespace,
                'labels' => $labels,
            ],
            'spec' => [
                'replicas' => $worker->numprocs,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => $name]],
                'strategy' => [
                    'type' => 'RollingUpdate',
                    'rollingUpdate' => ['maxUnavailable' => 1, 'maxSurge' => 0],
                ],
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'serviceAccountName' => $ctx->serviceAccountName,
                        'terminationGracePeriodSeconds' => $grace,
                        'securityContext' => $this->security->podSecurityContext(),
                        'containers' => [$container],
                        'topologySpreadConstraints' => [
                            [
                                'maxSkew' => 1,
                                'topologyKey' => 'kubernetes.io/hostname',
                                'whenUnsatisfiable' => 'ScheduleAnyway',
                                'labelSelector' => ['matchLabels' => ['app.kubernetes.io/name' => $name]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return new KubeObject('Deployment', $name, $ctx->namespace, $spec);
    }

    private function renderService(RenderContext $ctx): KubeObject
    {
        $spec = [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $ctx->appName,
                'namespace' => $ctx->namespace,
                'labels' => [
                    'app.kubernetes.io/name' => $ctx->appName,
                    'app.kubernetes.io/managed-by' => 'vortos-deploy',
                ],
            ],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => [
                    'app.kubernetes.io/name' => $ctx->appName,
                    'app.kubernetes.io/color' => 'blue',
                ],
                'ports' => [
                    ['port' => $ctx->port, 'targetPort' => $ctx->port, 'protocol' => 'TCP', 'name' => 'http'],
                ],
            ],
        ];

        return new KubeObject('Service', $ctx->appName, $ctx->namespace, $spec);
    }

    private function renderHpa(RenderContext $ctx, string $targetName, int $min = 0, int $max = 0): KubeObject
    {
        $hpaName = $targetName . '-hpa';
        $minReplicas = $min > 0 ? $min : $ctx->minReplicas;
        $maxReplicas = $max > 0 ? $max : $ctx->maxReplicas;

        $spec = [
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => [
                'name' => $hpaName,
                'namespace' => $ctx->namespace,
            ],
            'spec' => [
                'scaleTargetRef' => [
                    'apiVersion' => 'apps/v1',
                    'kind' => 'Deployment',
                    'name' => $targetName,
                ],
                'minReplicas' => max(2, $minReplicas),
                'maxReplicas' => max($maxReplicas, max(2, $minReplicas)),
                'metrics' => [
                    [
                        'type' => 'Resource',
                        'resource' => [
                            'name' => 'cpu',
                            'target' => ['type' => 'Utilization', 'averageUtilization' => $ctx->cpuTargetPercent],
                        ],
                    ],
                ],
            ],
        ];

        return new KubeObject('HorizontalPodAutoscaler', $hpaName, $ctx->namespace, $spec);
    }

    private function renderPdb(RenderContext $ctx, string $deploymentName): KubeObject
    {
        $pdbName = $deploymentName . '-pdb';
        $matchLabels = [
            'app.kubernetes.io/name' => $ctx->appName,
        ];

        if (str_contains($deploymentName, '-blue') || str_contains($deploymentName, '-green')) {
            $color = str_contains($deploymentName, '-blue') ? 'blue' : 'green';
            $matchLabels['app.kubernetes.io/color'] = $color;
        }

        $spec = [
            'apiVersion' => 'policy/v1',
            'kind' => 'PodDisruptionBudget',
            'metadata' => [
                'name' => $pdbName,
                'namespace' => $ctx->namespace,
            ],
            'spec' => [
                'maxUnavailable' => 1,
                'selector' => ['matchLabels' => $matchLabels],
            ],
        ];

        return new KubeObject('PodDisruptionBudget', $pdbName, $ctx->namespace, $spec);
    }

    /**
     * Render a one-shot migration Job from the app image.
     *
     * @param list<string> $command
     */
    public function renderMigrationJob(RenderContext $ctx, array $command, string $jobName = ''): KubeObject
    {
        if ($jobName === '') {
            $jobName = $ctx->appName . '-migrate-' . substr(md5(implode('-', $command)), 0, 8);
        }

        $container = [
            'name' => 'migrate',
            'image' => $ctx->imageReference,
            'args' => $command,
            'securityContext' => $this->security->restricted(),
        ];

        if ($ctx->secretRefs !== []) {
            $container['envFrom'] = array_map(
                fn (string $secretName) => ['secretRef' => ['name' => $secretName]],
                array_values($ctx->secretRefs),
            );
        }

        $spec = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $jobName,
                'namespace' => $ctx->namespace,
            ],
            'spec' => [
                'backoffLimit' => 0,
                'template' => [
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'serviceAccountName' => $ctx->serviceAccountName,
                        'securityContext' => $this->security->podSecurityContext(),
                        'containers' => [$container],
                    ],
                ],
            ],
        ];

        return new KubeObject('Job', $jobName, $ctx->namespace, $spec);
    }
}
