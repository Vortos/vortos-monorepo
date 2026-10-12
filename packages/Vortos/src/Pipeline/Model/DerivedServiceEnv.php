<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A service's runtime env built from the app-wide sealed env, keeping only the names it declares (RC-7).
 *
 * WHY. Every service that mounted the app-wide runtime env received every application secret in it.
 * The backup and scheduler node mounted it to reach the database, the cache and the broker — and so
 * held the payment-gateway credentials, the payout encryption key and the token signing key it never
 * uses, on the one node that can also open the backups and drive the Docker API. A second sealed file
 * holding copies of the names it does need would drift from the original the first time a shared value
 * (a DSN, a host) was rotated in one and not the other.
 *
 * So the file is DERIVED: the deploy opens the same sealed source as the app-wide env and writes only
 * {@see $names} to `<remoteDeployDir>/<target>`, with exactly {@see $mode} and {@see $owner}, before
 * the topology is synced. A declared name absent from the source fails the deploy — a service must not
 * quietly boot without a variable someone decided it needs. {@see $services} is the complete audience,
 * and {@see \Vortos\Pipeline\Topology\EnvFileScopeRule} refuses a service that mounts this file AND the
 * app-wide runtime env, which would hand it everything the derivation withholds.
 *
 * Names only — the declaration never carries a value. No field has a default.
 */
final readonly class DerivedServiceEnv
{
    /** @var list<string> */
    public array $names;

    /**
     * @param list<string> $names the variables this service receives from the app-wide env
     */
    public function __construct(
        public HostEnvFileName $target,
        array $names,
        public SecretFileMode $mode,
        public FileOwner $owner,
        public ComposeServiceSet $services,
    ) {
        if ($names === []) {
            throw new \InvalidArgumentException(sprintf('Derived env "%s" must name at least one variable.', $target->value));
        }

        foreach ($names as $name) {
            // @phpstan-ignore function.alreadyNarrowedType (config/pipeline.php is untyped PHP)
            if (!\is_string($name)) {
                throw new \InvalidArgumentException(sprintf('Derived env "%s" names must be strings, got %s.', $target->value, get_debug_type($name)));
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Derived env "%s" names must be environment variable names, got "%s".',
                    $target->value,
                    $name,
                ));
            }
        }

        if (\count(array_unique($names)) !== \count($names)) {
            throw new \InvalidArgumentException(sprintf('Derived env "%s" names must not repeat.', $target->value));
        }

        $sorted = $names;
        sort($sorted);
        $this->names = $sorted;
    }
}
