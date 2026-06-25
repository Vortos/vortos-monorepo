<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Manifest;

use Vortos\DeployK8s\Api\KubeObject;

final class RbacRenderer
{
    /** @return list<KubeObject> */
    public function render(RenderContext $ctx): array
    {
        $roleName = $ctx->appName . '-deployer';

        $role = new KubeObject('Role', $roleName, $ctx->namespace, [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'Role',
            'metadata' => [
                'name' => $roleName,
                'namespace' => $ctx->namespace,
            ],
            'rules' => [
                [
                    'apiGroups' => [''],
                    'resources' => ['services'],
                    'verbs' => ['get', 'patch'],
                ],
                [
                    'apiGroups' => [''],
                    'resources' => ['pods'],
                    'verbs' => ['get', 'list'],
                ],
                [
                    'apiGroups' => ['apps'],
                    'resources' => ['deployments'],
                    'verbs' => ['get', 'create', 'update', 'patch'],
                ],
                [
                    'apiGroups' => ['apps'],
                    'resources' => ['deployments/scale'],
                    'verbs' => ['update', 'patch'],
                ],
                [
                    'apiGroups' => ['batch'],
                    'resources' => ['jobs'],
                    'verbs' => ['create', 'get'],
                ],
                [
                    'apiGroups' => ['autoscaling'],
                    'resources' => ['horizontalpodautoscalers'],
                    'verbs' => ['get', 'create', 'update', 'patch'],
                ],
                [
                    'apiGroups' => ['policy'],
                    'resources' => ['poddisruptionbudgets'],
                    'verbs' => ['get', 'create', 'update', 'patch'],
                ],
            ],
        ]);

        $bindingName = $ctx->appName . '-deployer-binding';

        $binding = new KubeObject('RoleBinding', $bindingName, $ctx->namespace, [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'RoleBinding',
            'metadata' => [
                'name' => $bindingName,
                'namespace' => $ctx->namespace,
            ],
            'roleRef' => [
                'apiGroup' => 'rbac.authorization.k8s.io',
                'kind' => 'Role',
                'name' => $roleName,
            ],
            'subjects' => [
                [
                    'kind' => 'ServiceAccount',
                    'name' => $ctx->serviceAccountName,
                    'namespace' => $ctx->namespace,
                ],
            ],
        ]);

        return [$role, $binding];
    }
}
