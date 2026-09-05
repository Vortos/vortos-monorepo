<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Compose;

use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\ProcessCommandRunner;
use Vortos\Deploy\Topology\TopologyValidatorInterface;

/**
 * Validates a topology with the Compose CLI itself — the parser that will have to run it.
 *
 * The deploy-ops image carries the plugin, so on the node where a topology is actually installed the
 * shipped file can be checked by the real thing rather than by an approximation of it. Where the
 * plugin is absent this reports no objection: the caller's structural checks still apply, and a
 * missing tool is not evidence of a bad file.
 *
 * Every subprocess goes through the runner rather than a shell primitive — argv is a list, so no
 * amount of odd characters in a path can turn into an injection.
 */
final class ComposeCliValidator implements TopologyValidatorInterface
{
    public function __construct(
        private readonly CommandRunnerInterface $runner = new ProcessCommandRunner(),
    ) {}

    public function validate(string $path): ?string
    {
        if (!$this->runner->run(['docker', 'compose', 'version'])->isSuccess()) {
            return null;
        }

        $result = $this->runner->run(['docker', 'compose', '-f', $path, 'config', '-q']);

        if ($result->isSuccess()) {
            return null;
        }

        // Interpolation warnings are expected and do not fail: a production topology references
        // runtime variables that live in the host env file, which a validator has no business
        // reading. Only a non-zero exit — a file the parser cannot resolve — is an objection.
        $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

        return sprintf(
            'the topology in the release image is not valid (exit %d): %s — refusing to replace a '
            . 'working host file with it',
            $result->exitCode,
            $detail !== '' ? $detail : 'no output',
        );
    }
}
