<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * An absolute host path a compose service bind-mounts that the pipeline deliberately does NOT deliver —
 * the Docker socket, the container log directory, the host root for filesystem metrics (RC-3).
 *
 * Declaring it is still required. These are the most privileged mounts a topology can make, and each
 * used to be one unreviewed line in a compose file: the host root reached a third-party log agent, and
 * with it every plaintext secret in the deploy dir, until someone noticed and shadowed it. The
 * declaration names the audience, the access and the reason; the topology policy refuses anything
 * wider, and refuses a mount that exposes the deploy dir without a tmpfs shadow over it.
 */
final readonly class HostSystemBind
{
    public string $path;

    public function __construct(
        string $path,
        public BindAccess $access,
        public ComposeServiceSet $services,
        public string $reason,
    ) {
        if (preg_match('#^/([A-Za-z0-9_][A-Za-z0-9_.-]*(/[A-Za-z0-9_][A-Za-z0-9_.-]*)*)?$#', $path) !== 1
            || preg_match('#/\.\.?(/|$)#', $path) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'Host system bind paths must be normalised absolute paths without traversal or shell metacharacters, got "%s".',
                $path,
            ));
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException(sprintf('Host system bind "%s" must state why the service needs it.', $path));
        }

        $this->path = $path;
    }
}
