<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\DeployK8s\Api\KubeApiInterface;
use Vortos\DeployK8s\Api\KubectlKubeApi;
use Vortos\DeployK8s\Edge\KubernetesEdgeRouter;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Target\KubernetesStepExecutor;
use Vortos\DeployK8s\Target\KubernetesTarget;
use Vortos\DeployK8s\Worker\KubernetesWorkerController;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class DeployK8sExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_deploy_k8s';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(KubectlKubeApi::class, KubectlKubeApi::class)
            ->setArgument('$runner', new Reference(CommandRunnerInterface::class))
            ->setPublic(false);

        $container->setAlias(KubeApiInterface::class, KubectlKubeApi::class)->setPublic(false);

        $container->register(PodSecurityProfile::class, PodSecurityProfile::class)
            ->setPublic(false);

        $container->register(RbacRenderer::class, RbacRenderer::class)
            ->setPublic(false);

        $container->register(KubernetesManifestRenderer::class, KubernetesManifestRenderer::class)
            ->setArgument('$security', new Reference(PodSecurityProfile::class))
            ->setArgument('$rbac', new Reference(RbacRenderer::class))
            ->setPublic(false);

        $container->register(KubernetesStepExecutor::class, KubernetesStepExecutor::class)
            ->setArgument('$kubeApi', new Reference(KubeApiInterface::class))
            ->setArgument('$stateStore', new Reference(DeployStateStoreInterface::class))
            ->setArgument('$renderer', new Reference(KubernetesManifestRenderer::class))
            ->setArgument('$workerRegistry', new Reference(WorkerProcessRegistry::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        $container->register(KubernetesTarget::class, KubernetesTarget::class)
            ->setArgument('$planner', new Reference(DeployPlanner::class))
            ->setArgument('$executor', new Reference(KubernetesStepExecutor::class))
            ->setArgument('$registry', new Reference(ContainerRegistryInterface::class))
            ->setArgument('$stateStore', new Reference(DeployStateStoreInterface::class))
            ->setArgument('$kubeApi', new Reference(KubeApiInterface::class))
            ->setArgument('$rollbackGuard', new Reference(RollbackGuard::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE))
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->register(KubernetesWorkerController::class, KubernetesWorkerController::class)
            ->setArgument('$kubeApi', new Reference(KubeApiInterface::class))
            ->setAutoconfigured(true)
            ->setPublic(false);

        $container->register(KubernetesEdgeRouter::class, KubernetesEdgeRouter::class)
            ->setArgument('$kubeApi', new Reference(KubeApiInterface::class))
            ->setAutoconfigured(true)
            ->setPublic(false);
    }
}
