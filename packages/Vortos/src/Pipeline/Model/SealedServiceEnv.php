<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A secret env file scoped to named compose services, delivered sealed (RC-4).
 *
 * WHY. Sealed delivery used to exist only for the app-wide `.env.prod`, which reaches the internet-facing
 * app and worker colours. A credential that must NOT reach them — the backup bucket token, the key that
 * opens the backups — therefore had nowhere to go but a hand-made 0600 file on the box: not versioned,
 * not reproducible on a rebuilt host, and with no record of which container was meant to receive it.
 *
 * The ciphertext is committed and ships inside the signed image; the deploy one-shot opens it with the
 * secret-store identity and writes `<remoteDeployDir>/<target>` with exactly {@see $mode} and
 * {@see $owner} before the topology is synced, failing the deploy if it cannot. {@see $services} is the
 * complete list of services allowed to mount it, enforced against the compose topology by
 * {@see \Vortos\Pipeline\Topology\EnvFileScopeRule}.
 *
 * No field has a default: mode, owner and audience are decisions, not omissions.
 */
final readonly class SealedServiceEnv
{
    public function __construct(
        /** Image-relative path to the sealed blob, e.g. `deploy/secrets/backup-r2.env.sealed`. */
        public string $sealedPath,
        public HostEnvFileName $target,
        public SecretFileMode $mode,
        public FileOwner $owner,
        public ComposeServiceSet $services,
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
