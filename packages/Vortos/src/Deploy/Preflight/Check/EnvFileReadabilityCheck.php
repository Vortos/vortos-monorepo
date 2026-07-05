<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Fail-closed env-file readability gate (GAP-A).
 *
 * In the deploy-in-image topology the blue-green cutover runs docker compose up for the color from
 * inside a throwaway one-shot, which parses each env_file: at load time as the image's *non-root*
 * uid. If a runtime env file is provisioned 0600 (or owned by a group the one-shot isn't in), that
 * parse fails with "permission denied" and the color never boots — but only at cutover, after every
 * other check has passed.
 *
 * This check runs inside that same one-shot (with the same --group-add posture the deploy applies),
 * so is_readable() on each declared runtime env file is the ground truth for whether the compose
 * parser will be able to read it. It catches the wrong-posture case before the cutover instead of at
 * it.
 *
 * A file that is simply absent in the current context (e.g. deploy:doctor run off the target) is a
 * Skip, not a Fail: presence is asserted by the mount/compose plumbing, and this check only judges
 * readability of files that are actually here.
 */
final class EnvFileReadabilityCheck implements PreflightCheckInterface
{
    /** @var \Closure(string): bool */
    private \Closure $isReadable;

    /**
     * @param (\Closure(string): bool)|null $isReadable readability probe; defaults to is_readable().
     *        Injectable so the fail path is testable regardless of the test-runner uid (root bypasses
     *        filesystem permission bits, which would otherwise make a wrong-posture test flaky).
     */
    public function __construct(?\Closure $isReadable = null)
    {
        $this->isReadable = $isReadable ?? static fn (string $path): bool => is_readable($path);
    }

    public function id(): string
    {
        return 'envfile.readable_by_runtime';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $envFiles = $context->definition->runtimeService->envFiles;

        if ($envFiles === []) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'no runtime env files declared; nothing to read',
            );
        }

        $unreadable = [];
        $checked = 0;
        foreach ($envFiles as $path) {
            if (!file_exists($path)) {
                // Not present in this context — presence is asserted elsewhere (the B19 mount). This
                // check only judges the posture of files that are actually here.
                continue;
            }

            $checked++;
            if (!($this->isReadable)($path)) {
                $unreadable[] = sprintf('%s (%s)', $path, $this->describePosture($path));
            }
        }

        if ($unreadable !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'a runtime env file is not readable by the deploy one-shot uid',
                sprintf(
                    'the nested cutover docker compose up parses env_file: as the image uid and would '
                    . 'fail "permission denied" for: %s',
                    implode(', ', $unreadable),
                ),
                'Provision each runtime env file group-readable by the one-shot (e.g. chown '
                . 'deploy:opc <file> && chmod 640) so the deploy\'s --group-add of the file gid grants '
                . 'read. Never make it world-readable.',
            );
        }

        if ($checked === 0) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'declared runtime env files are not present in this context; readability asserted on the target',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('all %d present runtime env file(s) are readable by the one-shot uid', $checked),
        );
    }

    /** A short, secret-free posture descriptor (octal mode + owner) for the failure message. */
    private function describePosture(string $path): string
    {
        $perms = @fileperms($path);
        $mode = $perms === false ? '????' : substr(sprintf('%o', $perms), -4);

        $owner = 'uid?';
        $group = 'gid?';
        if (\function_exists('posix_getpwuid')) {
            $uid = @fileowner($path);
            $gid = @filegroup($path);
            if ($uid !== false) {
                $pw = @posix_getpwuid($uid);
                $owner = is_array($pw) ? $pw['name'] : (string) $uid;
            }
            if ($gid !== false) {
                $gr = @posix_getgrgid($gid);
                $group = is_array($gr) ? $gr['name'] : (string) $gid;
            }
        }

        return sprintf('mode=%s owner=%s:%s', $mode, $owner, $group);
    }
}
