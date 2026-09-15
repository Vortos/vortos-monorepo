<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A secret env file only the deploy tooling reads — never a running service.
 *
 * WHY. Some credentials belong to the act of deploying, not to anything that serves: the database owner role that
 * runs migrations and applies grants is the clearest. Put it in `.env.prod` and every colour, worker and sidecar
 * holds a role that can alter every table — the least-privilege split between the owner and the runtime role
 * would exist on paper only. A {@see SealedServiceEnv} cannot carry it either: its audience is compose services.
 *
 * The ciphertext is committed and ships inside the signed image; the deploy opens it with the secret-store
 * identity into `<remoteDeployDir>/<target>` (exactly {@see $mode} and {@see $owner}) and passes it to each deploy
 * one-shot as an `--env-file` listed AFTER the runtime env file, so its keys override the runtime credential for
 * that one-shot alone. The docker CLI reads it on the host, as the deploy user — so {@see $owner} is that user
 * and no container is granted any group to read it. {@see \Vortos\Pipeline\Topology\EnvFileScopeRule} refuses the
 * file on every compose service.
 *
 * No field has a default: mode and owner are decisions, not omissions.
 */
final readonly class SealedToolingEnv
{
    public function __construct(
        /** Image-relative path to the sealed blob, e.g. `deploy/secrets/db-owner.env.sealed`. */
        public string $sealedPath,
        public HostEnvFileName $target,
        public SecretFileMode $mode,
        /** The deploy user whose docker CLI reads the file on the host. */
        public FileOwner $owner,
    ) {
        if (preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._/-]*\.sealed$#', $sealedPath) !== 1
            || preg_match('#(^|/)\.\.?(/|$)#', $sealedPath) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'Sealed env paths must be image-relative *.sealed paths without traversal or shell metacharacters, got "%s".',
                $sealedPath,
            ));
        }
    }
}
