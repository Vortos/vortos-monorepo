<?php

declare(strict_types=1);

return [
    'supply_chain' => [
        'signer' => 'null',
        'scanner' => 'null',
        'sbom_generator' => 'null',
        'kev_provider' => 'null',
        'verification_enabled' => false,
        'dev_unsafe_skip_verification' => false,
        'cve_gate' => [
            'fail_on' => 'critical',
            'fail_on_kev_any_severity' => true,
            'require_fix_available' => false,
        ],
    ],
];
