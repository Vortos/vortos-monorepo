<?php

declare(strict_types=1);

namespace Vortos\Foundation;

use CachedContainer;
use Vortos\Http\Controller\ErrorController;
use Psr\Log\LoggerInterface;
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
    private ?Container $container = null;
    private ?SymfonyResponse $response = null;
    private ?Request $request = null;
    private ?\Throwable $bootError = null;
    private readonly string $containerPath;
    private array $parameters = [];
    private bool $withRoutes = true;

    /** Cache directory for compiled container dumps. */
    private readonly string $cacheDir;

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

    private function getCompiledContainer(): Container
    {
        if ($this->environment === 'prod' && $this->context === 'http') {
            $dumpPath = $this->resolveContainerDumpPath();

            if ($dumpPath !== null) {
                require_once $dumpPath;
                return new CachedContainer();
            }
        }

        $projectRoot = $this->projectRoot;
        $container   = include $this->containerPath;

        $this->configureContainer($container);

        // Env placeholders (%env(...)%) baked into container parameters by
        // compiler passes (e.g. vortos.transports) are only resolved to real
        // values when:
        //   (a) compile(true) resolves them directly into the ContainerBuilder, or
        //   (b) the PhpDumper-generated CachedContainer emits getEnv() calls that
        //       resolve them lazily at runtime.
        // The dump path (b) only happens for prod+http (see dumpContainer()).
        // Every other context — CLI commands, queue workers, dev/test http —
        // gets the raw ContainerBuilder back, so it must take path (a) or any
        // parameter containing an env reference would leak as Symfony's internal
        // "env_<hash>_NAME_<hash>" placeholder token instead of its real value.
        $resolveEnvPlaceholders = $this->environment !== 'prod' || $this->context !== 'http';

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

        $this->dumpContainer($container);

        return $container;
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
        $path = $this->cacheDir . '/container_' . $hash . '.php';

        if (file_exists($path)) {
            return $path;
        }

        // Clean up stale container dumps from previous deploys.
        foreach (glob($this->cacheDir . '/container_*.php') ?: [] as $stale) {
            if ($stale !== $path) {
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
    }

    private function dumpContainer(Container $container): void
    {
        if ($this->environment !== 'prod' || $this->context !== 'http') {
            return;
        }

        $hash     = $this->configHash();
        $dumpPath = $this->cacheDir . '/container_' . $hash . '.php';

        if (file_exists($dumpPath)) {
            return;
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // PID-scoped tmp prevents two racing workers from corrupting the same file.
        $tmpPath  = $this->cacheDir . '/container_' . $hash . '_' . getmypid() . '.tmp';
        $lockPath = $this->cacheDir . '/container_' . $hash . '.lock';

        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            return;
        }

        // Non-blocking: if another worker already holds the lock, skip — it will
        // write the dump and the next request will pick it up via resolveContainerDumpPath().
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return;
        }

        try {
            // Re-check under lock — another worker may have finished between our check and here.
            if (!file_exists($dumpPath)) {
                $dumper = new PhpDumper($container);
                file_put_contents($tmpPath, $dumper->dump(['class' => 'CachedContainer']));
                rename($tmpPath, $dumpPath);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
            @unlink($tmpPath);
        }
    }

    /**
     * Produces a stable hash from config source files that affect the compiled container.
     * A changed hash means the container must be recompiled.
     */
    private function configHash(): string
    {
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

        return substr(hash('xxh3', implode("\n", $fingerprints)), 0, 16);
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
