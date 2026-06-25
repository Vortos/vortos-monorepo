<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\DeployK8s\Api\KubeObject;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Manifest\RenderContext;

final class LeastPrivilegeRbacTest extends TestCase
{
    public function test_role_has_no_wildcard_verbs(): void
    {
        $rbac = new RbacRenderer();
        $ctx = new RenderContext();
        $objects = $rbac->render($ctx);

        $role = $this->findByKind($objects, 'Role');
        $this->assertNotNull($role);

        $rules = $role->spec['rules'] ?? [];
        foreach ($rules as $rule) {
            $this->assertNotContains('*', $rule['verbs'], 'RBAC Role must not use wildcard verbs.');
            $this->assertNotContains('*', $rule['resources'], 'RBAC Role must not use wildcard resources.');
            $this->assertNotContains('*', $rule['apiGroups'], 'RBAC Role must not use wildcard apiGroups.');
        }
    }

    public function test_role_only_grants_required_resources(): void
    {
        $rbac = new RbacRenderer();
        $ctx = new RenderContext();
        $objects = $rbac->render($ctx);

        $role = $this->findByKind($objects, 'Role');
        $this->assertNotNull($role);

        $allResources = [];
        foreach ($role->spec['rules'] ?? [] as $rule) {
            $allResources = array_merge($allResources, $rule['resources']);
        }

        $allowed = [
            'services', 'pods', 'deployments', 'deployments/scale',
            'jobs', 'horizontalpodautoscalers', 'poddisruptionbudgets',
        ];

        foreach ($allResources as $resource) {
            $this->assertContains(
                $resource,
                $allowed,
                sprintf('RBAC Role grants access to unexpected resource "%s".', $resource),
            );
        }
    }

    public function test_role_binding_references_correct_service_account(): void
    {
        $rbac = new RbacRenderer();
        $ctx = new RenderContext(serviceAccountName: 'my-deployer');
        $objects = $rbac->render($ctx);

        $binding = $this->findByKind($objects, 'RoleBinding');
        $this->assertNotNull($binding);

        $subjects = $binding->spec['subjects'] ?? [];
        $this->assertCount(1, $subjects);
        $this->assertSame('my-deployer', $subjects[0]['name']);
        $this->assertSame('ServiceAccount', $subjects[0]['kind']);
    }

    public function test_role_verbs_are_minimal(): void
    {
        $rbac = new RbacRenderer();
        $ctx = new RenderContext();
        $objects = $rbac->render($ctx);

        $role = $this->findByKind($objects, 'Role');
        $this->assertNotNull($role);

        $allVerbs = [];
        foreach ($role->spec['rules'] ?? [] as $rule) {
            $allVerbs = array_merge($allVerbs, $rule['verbs']);
        }
        $allVerbs = array_unique($allVerbs);

        $this->assertNotContains('delete', $allVerbs, 'Deployer Role should not grant delete — scale to 0 instead.');
        $this->assertNotContains('deletecollection', $allVerbs);
    }

    /** @param list<KubeObject> $objects */
    private function findByKind(array $objects, string $kind): ?KubeObject
    {
        foreach ($objects as $obj) {
            if ($obj->kind === $kind) {
                return $obj;
            }
        }
        return null;
    }
}
