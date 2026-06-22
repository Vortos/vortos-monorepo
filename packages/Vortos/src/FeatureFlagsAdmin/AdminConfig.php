<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin;

final readonly class AdminConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $prefix = '/admin/flags',
        public string $requiredRole = 'ROLE_ADMIN',
    ) {}
}
