<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Migration\Safety\SchemaDriftAuditorInterface;

final class MigrationDriftCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly SchemaDriftAuditorInterface $auditor,
    ) {}

    public function id(): string
    {
        return 'migration.drift';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            $findings = $this->auditor->audit();
        } catch (\Throwable $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'schema drift check failed (unreachable target)',
                $e->getMessage(),
                'Ensure the database is reachable and credentials are valid.',
            );
        }

        $drifted = array_filter($findings, static fn ($f) => $f->hasDrift);

        if ($drifted === []) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'no schema drift detected between declared and running schema',
            );
        }

        $details = [];
        foreach ($drifted as $finding) {
            $prefix = $finding->unreachable ? '[unreachable] ' : '';
            $details[] = sprintf('%s%s: %s', $prefix, $finding->module, $finding->detail);
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            sprintf('schema drift detected in %d module(s)', count($drifted)),
            implode("\n", $details),
            'Resolve drift by running vortos:migrate or investigating manual schema changes before deploying.',
        );
    }
}
