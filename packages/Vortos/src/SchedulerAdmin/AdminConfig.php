<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin;

final readonly class AdminConfig
{
    public function __construct(
        public bool   $enabled             = true,
        public string $prefix              = '/admin/scheduler',
        public string $requiredRole        = 'ROLE_SCHEDULER_ADMIN',
        public int    $tokenFreshnessSec   = 900,
        public string $twoFaChallengeUrl   = '/auth/2fa/challenge',
        public string $loginUrl            = '/login',
        public string $assetBasePath       = '/bundles/scheduler-admin/build',
        public int    $previewMaxCount     = 10,
    ) {}
}
