<?php

declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionClassConstant;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Authorization\Middleware\ControllerPermissionMap;
use Vortos\Authorization\Permission\PermissionCatalogInterface;
use Vortos\Authorization\Permission\PermissionRegistry;

final class PermissionRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        [$permissions, $defaultGrants] = $this->discoverPermissions($container);
        $controllerMap = $this->buildControllerPermissionMap($container, $permissions);

        if ($container->hasDefinition(PermissionRegistry::class)) {
            $container->getDefinition(PermissionRegistry::class)
                ->setArgument('$permissions', $permissions)
                ->setArgument('$defaultGrants', $defaultGrants);
        }

        if ($container->hasDefinition(ControllerPermissionMap::class)) {
            $container->getDefinition(ControllerPermissionMap::class)
                ->setArgument('$map', $controllerMap);
        }
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string[]>}
     */
    private function discoverPermissions(ContainerBuilder $container): array
    {
        $permissions = [];
        $defaultGrants = [];

        foreach ($container->findTaggedServiceIds('vortos.permission_catalog') as $serviceId => $tags) {
            $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $catalogAttribute = $this->catalogAttribute($reflection, $tags, $serviceId);
            $resource = $catalogAttribute->resource;
            $group = $catalogAttribute->group;
            $meta = $this->catalogMeta($class);
            $catalogPermissions = [];

            foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $value = $constant->getValue();

                if (!is_string($value)) {
                    continue;
                }

                $permission = $this->normalizePermission($resource, $value, $class);
                [$permissionResource, $action, $scope] = $this->splitPermission($permission, $class);

                if ($permissionResource !== $resource) {
                    throw new \LogicException(sprintf(
                        'Permission catalog "%s" is for resource "%s" but constant "%s" resolves to "%s".',
                        $class,
                        $resource,
                        $constant->getName(),
                        $permission,
                    ));
                }

                if (isset($permissions[$permission])) {
                    throw new \LogicException(sprintf(
                        'Duplicate permission "%s" discovered in "%s" and "%s".',
                        $permission,
                        $permissions[$permission]['catalogClass'],
                        $class,
                    ));
                }

                $metadata = $meta[$value] ?? $meta[$permission] ?? [];
                $permissions[$permission] = [
                    'permission' => $permission,
                    'resource' => $resource,
                    'action' => $action,
                    'scope' => $scope,
                    'label' => isset($metadata['label']) && is_string($metadata['label'])
                        ? $metadata['label']
                        : $this->labelFor($permission),
                    'description' => isset($metadata['description']) && is_string($metadata['description'])
                        ? $metadata['description']
                        : null,
                    'dangerous' => (bool) ($metadata['dangerous'] ?? false),
                    'bypassable' => (bool) ($metadata['bypassable'] ?? false),
                    'group' => $group,
                    'catalogClass' => $class,
                ];

                $catalogPermissions[$value] = $permission;
                $catalogPermissions[$permission] = $permission;
            }

            foreach ($this->catalogGrants($class) as $role => $grants) {
                foreach ($grants as $grant) {
                    if (!is_string($grant)) {
                        throw new \LogicException(sprintf(
                            'Permission catalog "%s" has a non-string grant for role "%s".',
                            $class,
                            $role,
                        ));
                    }

                    $permission = $catalogPermissions[$grant] ?? $this->normalizePermission($resource, $grant, $class);

                    if (!isset($permissions[$permission])) {
                        throw new \LogicException(sprintf(
                            'Permission catalog "%s" grants unknown permission "%s" to role "%s".',
                            $class,
                            $grant,
                            $role,
                        ));
                    }

                    $defaultGrants[$role][$permission] = true;
                }
            }
        }

        ksort($permissions);

        foreach ($defaultGrants as $role => $grantIndex) {
            $defaultGrants[$role] = array_keys($grantIndex);
            sort($defaultGrants[$role]);
        }

        ksort($defaultGrants);

        return [$permissions, $defaultGrants];
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     */
    private function catalogAttribute(ReflectionClass $reflection, array $tags, string $serviceId): PermissionCatalog
    {
        $resource = $tags[0]['resource'] ?? null;
        $group = $tags[0]['group'] ?? null;

        if (is_string($resource)) {
            return new PermissionCatalog($resource, is_string($group) ? $group : null);
        }

        $attributes = $reflection->getAttributes(PermissionCatalog::class);

        if ($attributes === []) {
            throw new \LogicException(sprintf(
                'Permission catalog service "%s" must use #[PermissionCatalog(resource: "...")].',
                $serviceId,
            ));
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalogMeta(string $class): array
    {
        if (!is_subclass_of($class, PermissionCatalogInterface::class)) {
            return [];
        }

        $meta = $class::meta();

        if (!is_array($meta)) {
            throw new \LogicException(sprintf('Permission catalog "%s" meta() must return an array.', $class));
        }

        return $meta;
    }

    /**
     * @return array<string, string[]>
     */
    private function catalogGrants(string $class): array
    {
        if (!is_subclass_of($class, PermissionCatalogInterface::class)) {
            return [];
        }

        $grants = $class::grants();

        if (!is_array($grants)) {
            throw new \LogicException(sprintf('Permission catalog "%s" grants() must return an array.', $class));
        }

        return $grants;
    }

    private function normalizePermission(string $resource, string $permission, string $catalogClass): string
    {
        $parts = explode('.', $permission);

        if (count($parts) === 3) {
            return $permission;
        }

        if (count($parts) === 2) {
            return $resource . '.' . $permission;
        }

        throw new \LogicException(sprintf(
            'Permission catalog "%s" defines invalid permission "%s". Expected "action.scope" or "resource.action.scope".',
            $catalogClass,
            $permission,
        ));
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitPermission(string $permission, string $source): array
    {
        $parts = explode('.', $permission);

        if (count($parts) !== 3) {
            throw new \LogicException(sprintf(
                'Invalid permission "%s" in "%s". Expected "resource.action.scope".',
                $permission,
                $source,
            ));
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    private function labelFor(string $permission): string
    {
        [, $action, $scope] = explode('.', $permission);

        return ucfirst(str_replace(['_', '-'], ' ', $action))
            . ' '
            . str_replace(['_', '-'], ' ', $scope);
    }

    /**
     * @param array<string, array<string, mixed>> $permissions
     * @return array<string, list<array{
     *     permission: string,
     *     resourceParam: ?string,
     *     scope: string|array|null,
     *     scopeMode: string
     * }>>
     */
    private function buildControllerPermissionMap(ContainerBuilder $container, array $permissions): array
    {
        $map = [];

        foreach ($container->findTaggedServiceIds('vortos.api.controller') as $serviceId => $_tags) {
            $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $classRequirements = $this->requirementsFor($reflection->getAttributes(RequiresPermission::class));

            if ($classRequirements !== []) {
                $this->validateRequirements($classRequirements, $permissions, $class);
                $map[$class] = $classRequirements;
            }

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $methodRequirements = $this->requirementsFor($method->getAttributes(RequiresPermission::class));

                if ($methodRequirements === []) {
                    continue;
                }

                $key = $class . '::' . $method->getName();
                $this->validateRequirements($methodRequirements, $permissions, $key);
                $map[$key] = $methodRequirements;
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * @param \ReflectionAttribute<RequiresPermission>[] $attributes
     * @return list<array{
     *     permission: string,
     *     resourceParam: ?string,
     *     scope: string|array|null,
     *     scopeMode: string
     * }>
     */
    private function requirementsFor(array $attributes): array
    {
        $requirements = [];

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $requirements[] = [
                'permission' => $instance->permission,
                'resourceParam' => $instance->resourceParam,
                'scope' => $instance->scope,
                'scopeMode' => $instance->scopeMode->name,
            ];
        }

        return $requirements;
    }

    /**
     * @param list<array{permission: string}> $requirements
     * @param array<string, array<string, mixed>> $permissions
     */
    private function validateRequirements(array $requirements, array $permissions, string $source): void
    {
        foreach ($requirements as $requirement) {
            $permission = $requirement['permission'];

            if (!isset($permissions[$permission])) {
                throw new \LogicException(sprintf(
                    'Unknown permission "%s" used by "%s". Add it to a #[PermissionCatalog] class.',
                    $permission,
                    $source,
                ));
            }
        }
    }
}
