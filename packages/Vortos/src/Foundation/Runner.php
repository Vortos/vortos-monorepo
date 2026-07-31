<?php

declare(strict_types=1);

namespace Vortos\Foundation;

use Vortos\Http\Controller\ErrorController;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CheckTypeDeclarationsPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Foundation\Reset\ServicesResetter;

class Runner
{
    /**
     * Version of the on-disk container-dump contract.
     *
     * Bumped whenever a dump written by an older framework cannot be loaded by this one — the
     * class it declares, or the shape of the file. It is part of both the filename and the class
     * name, so an old dump can never be mistaken for a current one: it simply does not match the
     * name being looked for, and gets swept with the other stale artefacts.
     *
     * v2: dumps declare VortosCachedContainer_<format>_<hash> instead of a fixed CachedContainer.
     */
    private const DUMP_FORMAT = 2;

    private ?Container $container = null;
    private ?SymfonyResponse $response = null;
    private ?Request $request = null;
    private ?\Throwable $bootError = null;
    private readonly string $containerPath;
    private array $parameters = [];
    private bool $withRoutes = true;

    /** Cache directory for compiled container dumps. */
    private readonly string $cacheDir;

    /**
     * Memoised config hash. Computing it walks every php/yaml file under config/ and src/, and a
     * single boot asks for it up to four times (resolve, dump, class name, sweep). The inputs
     * cannot change within a boot in any way this process could act on — a config change means a
     * new deploy, which means new processes.
     */
    private ?string $configHash = null;

    public function __construct(
        private readonly string $environment,
        private readonly bool $debug,
        private readonly string $projectRoot,
        private readonly string $context = 'http',
    ) {
        $this->cacheDir      = $projectRoot . '/var/cache';
        $this->containerPath = __DIR__ . '/Bootstrap/Container.php';
        $this->withRoutes    = $this->context === 'http';
    }

    public function run(): SymfonyResponse
    {
        $request = $this->request = $this->getRequest();

        // Prod: a failed boot is permanent until redeploy — skip recompilation on every request.
        // Dev: always retry so fixing the code recovers without restarting the worker.
        if ($this->bootError !== null && !$this->debug) {
            return $this->handleBoostrapErrors(exception: $this->bootError, request: $request);
        }

        try {
            $this->getContainer();
            $this->bootError = null;
        } catch (\Throwable $e) {
            $this->bootError = $e;
            return $this->handleBoostrapErrors(exception: $e, request: $request);
        }

        try {
            $this->applyTrustedProxies();
            $kernel = $this->container->get('vortos');

            $this->response = $kernel->handle(
                request: $request
            );
        } catch (\Throwable $e) {
            $this->response = $this->handleBoostrapErrors(
                exception: $e,
                request: $request,
                container: $this->container
            );
        }

        return $this->response;
    }

    public function getContainer(): Container
    {
        if ($this->container === null) {
            $this->container = $this->getCompiledContainer();
        }

        return $this->container;
    }

    public function setParameter(string $name, mixed $value): static
    {
        $this->parameters[$name] = $value;
        return $this;
    }

    public function setParameters(array $parameters): self
    {
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }

    public function withRoutes(bool $enable = true): self
    {
        $this->withRoutes = $enable;
        return $this;
    }

    public function cleanUp(): void
    {
        // Fire kernel.terminate (terminable middleware: metrics/StatsD/log flush) AFTER the response
        // has been sent and BEFORE services are reset, so per-request state is still intact. In
        // FrankenPHP worker mode this is the ONLY thing that calls Kernel::terminate() — run() only
        // calls handle(). Without this, every terminable middleware (including the OTLP metrics flush)
        // would silently never run, and reset() below would drop the request's recorded telemetry.
        $this->terminateKernel();

        if ($this->container !== null && $this->container->has(ServicesResetter::class)) {
            $this->container->get(ServicesResetter::class)->reset();
        }

        if ($this->container !== null && $this->container->has(ArrayAdapter::class)) {
            $this->container->get(ArrayAdapter::class)->clear();
        }

        // In worker mode, keep the container alive between requests
        // Only reset the per-request request/response
        $this->response = null;
        $this->request  = null;

        // Only reset container in non-worker mode
        if (!function_exists('frankenphp_handle_request')) {
            $this->container = null;
        }
    }

    /**
     * Invokes Vortos\Http\Kernel::terminate() for the request just served, running all terminable
     * middleware. Guarded to the http context and a booted kernel; a failure here is logged and
     * swallowed so a flush error can never break the worker loop or skip the reset that follows.
     */
    private function terminateKernel(): void
    {
        if ($this->context !== 'http'
            || $this->container === null
            || $this->request === null
            || $this->response === null
            || !$this->container->has('vortos')
        ) {
            return;
        }

        try {
            $this->container->get('vortos')->terminate($this->request, $this->response);
        } catch (\Throwable $e) {
            $this->getLogger()?->error('http.terminate_failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    private function applyTrustedProxies(): void
    {
        if ($this->container === null) {
            return;
        }

        $proxies = $this->container->hasParameter('vortos.trusted_proxies')
            ? (array) $this->container->getParameter('vortos.trusted_proxies')
            : [];

        $hosts = $this->container->hasParameter('vortos.trusted_hosts')
            ? (array) $this->container->getParameter('vortos.trusted_hosts')
            : [];

        $this->validateTrustedProxies($proxies);

        if ($proxies !== []) {
            Request::setTrustedProxies(
                $proxies,
                Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
            );
        } elseif ($this->container->hasParameter('vortos.has_ip_rate_limits')
            && $this->container->getParameter('vortos.has_ip_rate_limits') === true
        ) {
            $this->getLogger()?->warning('rate_limit.ip_scope_without_trusted_proxies', [
                'detail' => 'IP-scoped rate limits are configured but vortos.trusted_proxies is empty. '
                    . 'Behind a reverse proxy, all clients will share one rate-limit bucket (the proxy IP). '
                    . 'Set trusted_proxies to your proxy IPs in config/http.php or via VORTOS_TRUSTED_PROXIES.',
            ]);
        }

        if ($hosts !== []) {
            Request::setTrustedHosts($hosts);
        }
    }

    private function validateTrustedProxies(array $proxies): void
    {
        foreach ($proxies as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException(
                    'Each trusted_proxies entry must be a string (IP or CIDR). Got: ' . get_debug_type($entry),
                );
            }

            if ($entry === '*' || $entry === 'REMOTE_ADDR') {
                throw new \InvalidArgumentException(
                    sprintf('Wildcard trusted proxy "%s" trusts all connecting IPs, enabling X-Forwarded-For spoofing. '
                        . 'List your actual proxy IPs/CIDRs instead.', $entry),
                );
            }

            if (str_contains($entry, '/')) {
                [$network, $prefix] = explode('/', $entry, 2);
                $prefixLen = (int) $prefix;

                $isV6 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
                $isV4 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

                if (!$isV4 && !$isV6) {
                    throw new \InvalidArgumentException(
                        sprintf('Invalid CIDR in trusted_proxies: "%s" — network address is not a valid IP.', $entry),
                    );
                }

                $minPrefix = $isV6 ? 16 : 8;
                if ($prefixLen < $minPrefix) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Overly broad CIDR in trusted_proxies: "%s" (prefix /%d < minimum /%d). '
                            . 'This would trust too many IPs, enabling X-Forwarded-For spoofing.',
                            $entry,
                            $prefixLen,
                            $minPrefix,
                        ),
                    );
                }
            } elseif (!filter_var($entry, FILTER_VALIDATE_IP)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid IP in trusted_proxies: "%s" — must be a valid IPv4/IPv6 address or CIDR.', $entry),
                );
            }
        }
    }

    private function getLogger(): ?LoggerInterface
    {
        try {
            if ($this->container?->has(LoggerInterface::class)) {
                return $this->container->get(LoggerInterface::class);
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function getRequest(): Request
    {
        return Request::createFromGlobals();
    }

    /**
     * Produces the container this process will serve from.
     *
     * The one invariant this method exists to hold: **a ContainerBuilder compiled with env
     * placeholders left unresolved is an intermediate artefact and must never be returned.**
     *
     * Env placeholders (%env(...)%) become real values in exactly two ways:
     *   (a) compile(true) resolves them into the ContainerBuilder, in-process, at compile time, or
     *   (b) a PhpDumper-generated container emits getEnv() calls that resolve them per boot.
     *
     * prod+http deliberately takes (b): resolving at compile time would bake the values into the
     * dump on disk — turning a cache file into a secrets file, and freezing env changes until the
     * config hash happens to move. Every other context (CLI commands, queue workers, dev/test http)
     * has no dump and therefore must take (a).
     *
     * What FB-45 was: prod+http compiled with resolution OFF and then returned that builder,
     * because dumping was treated as a side effect rather than as the step that produces the
     * runtime container. The worker that wrote the dump — and every worker that lost the race for
     * the lock — served a container in which EVERY env-backed argument was the literal Symfony
     * token "env_<hash>_NAME_<hash>". Under FrankenPHP that is not one bad request: the worker
     * holds that container for its whole life. It surfaced as a Mercure hub URL that was not a URL,
     * reaching browsers in an API response; the same tokens were in every other env-backed
     * argument on those workers at the same time. It hit every deploy, because a deploy is exactly
     * when the config hash moves and all workers boot at once with no dump to load.
     */
    private function getCompiledContainer(): Container
    {
        $servesFromDump = $this->environment === 'prod' && $this->context === 'http';

        if ($servesFromDump) {
            $dumpPath = $this->resolveContainerDumpPath();

            if ($dumpPath !== null) {
                $cached = $this->loadDumpedContainer($dumpPath);

                if ($cached !== null) {
                    return $cached;
                }
            }
        }

        $container = $this->buildContainer(resolveEnvPlaceholders: !$servesFromDump);

        if (!$servesFromDump) {
            // (a): placeholders are already real values in this builder.
            return $container;
        }

        $dumpPath = $this->dumpContainer($container);

        if ($dumpPath !== null) {
            // (b): the dump is the runtime container, not a by-product of having built one.
            $loaded = $this->loadDumpedContainer($dumpPath);

            if ($loaded !== null) {
                return $loaded;
            }
        }

        // The dump could not be produced — an unwritable cache dir, or a dumper failure. The
        // builder in hand is unusable by the invariant above, so pay for a second compile rather
        // than serve placeholder tokens. Correctness over boot time: this costs milliseconds once
        // per worker, and only in a degraded state that also wants investigating.
        return $this->buildContainer(resolveEnvPlaceholders: true);
    }

    /**
     * Compiles a fresh container from the bootstrap definition.
     *
     * Deliberately re-includes the container file rather than reusing a builder: compile() is a
     * one-shot operation, so the degraded second attempt in getCompiledContainer() needs a builder
     * that has never been compiled.
     *
     * Protected so the boot policy above can be tested against a container small enough to assert
     * on. The seam is the definition source only — every decision about what is safe to serve
     * stays in getCompiledContainer(), where the invariant lives.
     */
    protected function buildContainer(bool $resolveEnvPlaceholders): ContainerBuilder
    {
        $projectRoot = $this->projectRoot;
        $container   = include $this->containerPath;

        $this->configureContainer($container);

        try {
            $container->compile($resolveEnvPlaceholders);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'has been excluded')) {
                throw new \RuntimeException(
                    $e->getMessage()
                    . "\n\nHint: If this interface has an implementation class, add #[DefaultImpl] to it:"
                    . "\n\n    use Vortos\\Foundation\\DependencyInjection\\Attribute\\DefaultImpl;"
                    . "\n\n    #[DefaultImpl]"
                    . "\n    final class YourImpl implements YourInterface { ... }"
                    . "\n\nOr register the binding manually in config/services.php.",
                    0,
                    $e,
                );
            }
            throw $e;
        }

        return $container;
    }

    /**
     * Loads a dumped container and instantiates it, or returns null if the file on disk is not one
     * this version can use — in which case it is discarded so the caller rebuilds.
     *
     * Null rather than an exception because every reason this fails is recoverable by recompiling,
     * and a boot that CAN recover must not take the application down to report a cache problem.
     *
     * The dumped class is named per config hash rather than a fixed "CachedContainer". A process
     * that loads two different dumps — a worker outliving a config change, or a test suite booting
     * more than one project — would otherwise hit a fatal class redeclaration, which is a hard
     * crash with no useful message. Per-hash naming makes the two dumps simply two classes.
     */
    private function loadDumpedContainer(string $dumpPath): ?Container
    {
        $class = $this->dumpClassName($this->configHash());

        // `require`, not `require_once`: the class_exists guard below is what makes loading
        // idempotent, and it is the more precise guard. require_once keys on the PATH, so after a
        // stale dump was loaded and replaced in place, re-loading the same path would be skipped —
        // the freshly written dump would never be seen, and the rebuild would be thrown away on
        // every boot. Guarding on the class means a file is read exactly when its class is missing,
        // which is also the only condition under which redeclaration is impossible.
        if (!class_exists($class, autoload: false)) {
            require $dumpPath;
        }

        if (!class_exists($class, autoload: false)) {
            // A dump written by a different version of this class naming scheme, or a truncated
            // file. Reclaim it and let the caller compile: self-healing beats a boot loop that
            // needs someone to SSH in and delete a cache file.
            @unlink($dumpPath);

            return null;
        }

        /** @var Container $container */
        $container = new $class();

        return $container;
    }

    /** The class a dump declares. Per-hash, so two dumps can coexist in one process. */
    private function dumpClassName(string $hash): string
    {
        return 'VortosCachedContainer_' . self::DUMP_FORMAT . '_' . $hash;
    }

    /** Path a dump for this hash is published at. Format-tagged, so old dumps can never be loaded. */
    private function dumpPathFor(string $hash): string
    {
        return $this->cacheDir . '/container_v' . self::DUMP_FORMAT . '_' . $hash . '.php';
    }

    /**
     * Returns the path to a valid cached container dump, or null if none exists.
     *
     * The dump filename is content-hashed from the config source files so that
     * a new deploy atomically invalidates the old container without a race:
     * the new dump is written to a PID-scoped tmp file, then renamed into place.
     * Stale dumps from previous deploys are cleaned up on first boot.
     */
    private function resolveContainerDumpPath(): ?string
    {
        $hash = $this->configHash();
        $path = $this->dumpPathFor($hash);

        if (file_exists($path)) {
            return $path;
        }

        // Clean up artefacts of previous deploys and of previous dump formats: their dumps, and the
        // lock files those dumps were published under (dumpContainer() no longer unlinks its own
        // lock, so this is where they are reclaimed — for a hash nothing can still be waiting on).
        $keep = 'container_v' . self::DUMP_FORMAT . '_' . $hash;

        foreach (glob($this->cacheDir . '/container_*') ?: [] as $stale) {
            if (!str_starts_with(basename($stale), $keep)) {
                @unlink($stale);
            }
        }

        return null;
    }

    private function configureContainer(ContainerBuilder $container): void
    {
        $container->setParameter('kernel.env', $this->environment);
        $container->setParameter('kernel.debug', $this->debug);
        $container->setParameter('kernel.project_dir', $this->projectRoot);
        $container->setParameter('kernel.context', $this->context);
        $container->setParameter('kernel.enable_routes', $this->withRoutes);

        foreach ($this->parameters as $key => $value) {
            $container->setParameter($key, $value);
        }

        $this->addTypeDeclarationCheck($container);
    }

    /**
     * Verifies every wired argument against the constructor it is passed to, at compile time.
     *
     * Without this, a wiring type mismatch is invisible until the service is first instantiated —
     * and services are lazy, so "first instantiated" can mean "in production, an hour after the
     * deploy, in a process nobody is watching". That is not hypothetical: aliasing MetricsInterface
     * to a decorator that did not implement FlushableMetricsInterface passed every test, compiled
     * cleanly, deployed green, and then fatalled all forty Kafka consumers on boot. They
     * crash-looped, saturated the box, and the CPU probe failed the app's readiness — the site went
     * down from a type error the container already had all the information to reject.
     *
     * Symfony ships the check; it simply is not on by default. Turning it on for dev and test makes
     * every local run and every test a gate: the container refuses to compile and names the argument.
     *
     * Deliberately NOT enabled in prod. It autoloads and reflects over every definition, which costs
     * real boot time, and by then it is too late to be useful anyway — the same container was already
     * compiled in dev and test before it could be released. Prod keeps the cheap compile.
     */
    private function addTypeDeclarationCheck(ContainerBuilder $container): void
    {
        if ($this->environment === 'prod') {
            return;
        }

        $container->addCompilerPass(
            new CheckTypeDeclarationsPass(true),
            PassConfig::TYPE_AFTER_REMOVING,
            -100,
        );
    }

    /**
     * Writes the container dump and returns its path, or null if no usable dump exists.
     *
     * The return value is the point: the caller serves from the dump, so "did this produce one"
     * has to be an answer rather than an assumption (FB-45).
     *
     * The lock is BLOCKING. It used to be LOCK_NB, on the reasoning that a worker losing the race
     * could skip and let "the next request" pick the dump up — but the loser still had to serve
     * the request in hand, and what it had in hand was the unresolved builder. Waiting is bounded
     * by one dump write, happens once per worker per deploy, and is the difference between a
     * cold-start pause and a worker serving placeholder tokens for the rest of its life.
     */
    private function dumpContainer(ContainerBuilder $container): ?string
    {
        if ($this->environment !== 'prod' || $this->context !== 'http') {
            return null;
        }

        $hash     = $this->configHash();
        $dumpPath = $this->dumpPathFor($hash);

        if (file_exists($dumpPath)) {
            return $dumpPath;
        }

        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            return null;
        }

        // Unique per attempt, not per process: FrankenPHP workers are THREADS of one process, so a
        // PID-scoped name is the same name in every worker. The lock below is what actually
        // serialises writers; this only guarantees no two attempts can ever name the same tmp file.
        $prefix   = $this->cacheDir . '/container_v' . self::DUMP_FORMAT . '_' . $hash;
        $tmpPath  = $prefix . '_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.tmp';
        $lockPath = $prefix . '.lock';

        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            return null;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return null;
            }

            // Re-check under the lock: whoever held it before us has finished, and if they wrote
            // the dump this worker's own build is redundant — take theirs.
            if (file_exists($dumpPath)) {
                return $dumpPath;
            }

            $dumper  = new PhpDumper($container);
            $written = @file_put_contents(
                $tmpPath,
                $dumper->dump(['class' => $this->dumpClassName($hash)]),
            );

            // A partially written dump is worse than none: it would be required as valid PHP and
            // fail somewhere arbitrary. Publish only a complete file, and only by rename, which is
            // atomic within the filesystem.
            if ($written === false || !@rename($tmpPath, $dumpPath)) {
                @unlink($tmpPath);

                return null;
            }

            return $dumpPath;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            // The lock file is deliberately NOT unlinked. Removing it while another worker is
            // blocked on it hands the next opener a different inode, so two workers would hold
            // "the" lock at once — the exact race this file exists to prevent.
            @unlink($tmpPath);
        }
    }

    /**
     * Produces a stable hash from config source files that affect the compiled container.
     * A changed hash means the container must be recompiled.
     *
     * The project root is part of the input, not just the files under it: the hash also names the
     * class the dump declares, and two projects with coincidentally identical config (an empty
     * config/ in both, say) would otherwise want the same class name in one process.
     */
    private function configHash(): string
    {
        if ($this->configHash !== null) {
            return $this->configHash;
        }

        $sources = [
            $this->projectRoot . '/config',
            $this->projectRoot . '/src',
        ];

        $fingerprints = [];

        foreach ($sources as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }

                $ext = $file->getExtension();

                if ($ext !== 'php' && $ext !== 'yaml' && $ext !== 'yml') {
                    continue;
                }

                $fingerprints[] = $file->getMTime() . ':' . $file->getSize() . ':' . $file->getPathname();
            }
        }

        sort($fingerprints);

        array_unshift($fingerprints, 'root:' . $this->projectRoot);

        return $this->configHash = substr(hash('xxh3', implode("\n", $fingerprints)), 0, 16);
    }

    private function handleBoostrapErrors(\Throwable $exception, Request $request, ?Container $container = null): Response
    {
        try {
            $logger = null;
            if (isset($container)) {
                try {
                    if ($container->get(LoggerInterface::class)) {
                        $logger = $container->get(LoggerInterface::class);
                    }
                } catch (\Throwable $th) {
                }
            }

            $errorController = new ErrorController($this->debug, $logger);
            $response = $errorController->__invoke($exception, $request);
        } catch (\Throwable $e) {
            $response = new Response(
                $this->debug ? $e->getMessage() : 'Internal Server Error',
                500
            );
        }

        return $response;
    }
}
