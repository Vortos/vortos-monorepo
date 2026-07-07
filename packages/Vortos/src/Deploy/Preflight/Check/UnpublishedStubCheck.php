<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Migration\Service\UnpublishedStubDetector;

/**
 * R8-1: fail-closed gate for un-published vendor migration stubs.
 *
 * A framework bump can ship a new module migration stub (e.g. the scheduler fire-queue requeue
 * columns) that only becomes an app migration once 'vortos:migrate:publish' runs. The schema/phase
 * checks reason about *published* migrations only, so an un-published stub is invisible to preflight
 * and detonates at runtime ('SQLSTATE 42703: column … does not exist'). This check closes that gap:
 * if publishing would emit anything, the deploy is REFUSED before it mutates the target.
 *
 * Only loaded when vortos-migration is installed; registration in DeployExtension is guarded by
 * class_exists, like {@see MigrationDriftCheck}.
 */
final class UnpublishedStubCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly UnpublishedStubDetector $detector,
    ) {
    }

    public function id(): string
    {
        return 'schema.unpublished-stubs';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Schema;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            $report = $this->detector->detect();
        } catch (\Throwable $e) {
            // Fail-closed: an unreadable manifest / scan error must never read as "nothing pending".
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'could not determine whether module migration stubs are published',
                $e->getMessage(),
                'Ensure migrations/.vortos-published.json and the installed vortos/* packages are readable, then re-run.',
            );
        }

        if (!$report->hasUnpublished()) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'all module migration stubs are published',
            );
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            sprintf('%d module migration stub(s) are not published — their schema is not applied', $report->count()),
            sprintf('unpublished: [%s]', implode(', ', $report->labels())),
            'Run: php bin/console vortos:migrate:publish && php bin/console vortos:migrate — or enable '
            . 'deploy auto-publish (deploy --auto-publish, or ->autoPublishMigrations(true) in config/deploy.php).',
        );
    }
}
