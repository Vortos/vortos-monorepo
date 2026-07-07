<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vortos\Migration\Command\MigratePublishCommand;
use Vortos\Migration\Service\UnpublishedStubDetector;

/**
 * R8-1 opt-in auto-publish: runs the exact 'vortos:migrate:publish' logic (no duplication) in the
 * project tree so freshly-shipped module stubs become app migrations before the deploy doctor gate.
 *
 * This lives in the Deploy package (which already depends on Migration) rather than in Migration, so
 * the dependency direction stays Deploy → Migration. It is only invoked when the operator opts in
 * ('deploy --auto-publish' / 'config/deploy.php ->autoPublishMigrations(true)'); the default posture
 * remains the fail-closed {@see \Vortos\Deploy\Preflight\Check\UnpublishedStubCheck}.
 */
final class MigrationAutoPublisher implements MigrationAutoPublisherInterface
{
    public function __construct(
        private readonly MigratePublishCommand $publishCommand,
        private readonly UnpublishedStubDetector $detector,
    ) {
    }

    /**
     * @return int number of stubs published (0 when everything was already published)
     * @throws \RuntimeException when publishing fails (fail-closed: the deploy must not proceed on a
     *                            half-published migration set)
     */
    public function publish(): int
    {
        $before = $this->detector->detect()->count();
        if ($before === 0) {
            return 0;
        }

        $output = new BufferedOutput();
        $exitCode = $this->publishCommand->run(new ArrayInput([]), $output);

        if ($exitCode !== Command::SUCCESS) {
            throw new \RuntimeException(sprintf(
                'Auto-publish failed (exit %d); deploy refused. Publisher output: %s',
                $exitCode,
                trim($output->fetch()),
            ));
        }

        $remaining = $this->detector->detect()->count();
        if ($remaining !== 0) {
            throw new \RuntimeException(sprintf(
                'Auto-publish left %d stub(s) unpublished; deploy refused. Publisher output: %s',
                $remaining,
                trim($output->fetch()),
            ));
        }

        return $before;
    }
}
