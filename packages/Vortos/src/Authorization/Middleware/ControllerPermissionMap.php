<?php

declare(strict_types=1);

namespace Vortos\Authorization\Middleware;

final class ControllerPermissionMap
{
    /**
     * @param array<string, list<array{
     *     permission: string,
     *     resourceParam: ?string,
     *     scope: string|array|null,
     *     scopeMode: string
     * }>> $map
     */
    public function __construct(private readonly array $map = [])
    {
    }

    /**
     * @return list<array{
     *     permission: string,
     *     resourceParam: ?string,
     *     scope: string|array|null,
     *     scopeMode: string
     * }>
     */
    public function forController(string $class, ?string $method = null): array
    {
        $requirements = $this->map[$class] ?? [];

        if ($method !== null) {
            $requirements = array_merge(
                $requirements,
                $this->map[$class . '::' . $method] ?? [],
            );
        }

        return $requirements;
    }
}
