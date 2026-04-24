<?php

use Vortos\Authorization\DependencyInjection\VortosAuthorizationConfig;

return static function (VortosAuthorizationConfig $config): void {
    $config->roleHierarchy([
        'ROLE_SUPER_ADMIN'      => ['ROLE_ADMIN'],
        'ROLE_ADMIN'            => ['ROLE_FEDERATION_ADMIN'],
        'ROLE_FEDERATION_ADMIN' => ['ROLE_COACH', 'ROLE_JUDGE'],
        'ROLE_COACH'            => ['ROLE_USER'],
        'ROLE_JUDGE'            => ['ROLE_USER'],
    ]);
};
