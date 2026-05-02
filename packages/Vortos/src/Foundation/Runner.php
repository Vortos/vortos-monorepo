<?php

namespace Vortos\Foundation;

use CachedContainer;
use Vortos\Http\Controller\ErrorController;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Cache\Adapter\ArrayAdapter;

class Runner
{
    private ?Container $container = null;
    private ?Response $response = null;
    private readonly string $dumpFilePath;
    private readonly string $containerPath;
    private array $parameters = [];
    private bool $withRoutes = true;

    public function __construct(
        private readonly string $environment,
        private readonly bool $debug,
        private readonly string $projectRoot,
        private readonly string $context = 'http',
    ) {
        $this->dumpFilePath = $projectRoot . "/var/cache/container_dump.php";
        $this->containerPath = __DIR__ . "/Bootstrap/Container.php";
        $this->withRoutes = $this->context === 'http';
    }

    public function run(): Response
    {
        $request = $this->getRequest();

        try {
            $this->getContainer();
        } catch (\Throwable $e) {

            error_log('FATAL: Container compilation failed: ' . $e->getMessage());
            error_log($e->getTraceAsString());

            return new Response(
                $this->debug
                    ? '<h1>Container Error</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>'
                    : 'Service temporarily unavailable. Check application logs.',
                503,
                ['Content-Type' => 'text/html'],
            );
        }

        try {
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
        if ($this->container !== null && $this->container->has(ArrayAdapter::class)) {
            $this->container->get(ArrayAdapter::class)->clear();
        }

        // In worker mode, keep the container alive between requests
        // Only reset the response
        $this->response = null;

        // Only reset container in non-worker mode
        if (!function_exists('frankenphp_handle_request')) {
            $this->container = null;
        }
    }

    private function getRequest(): Request
    {
        return Request::createFromGlobals();
    }

    private function getCompiledContainer(): Container
    {
        if ($this->environment === 'prod' && $this->context === 'http' && file_exists($this->dumpFilePath)) {
            require_once $this->dumpFilePath;
            $container = new CachedContainer();
        } else {
            $projectRoot = $this->projectRoot;

            $container = include $this->containerPath;

            $this->configureContainer($container);

            $container->compile();

            $this->handleCachingContainer($container);
        }

        return $container;
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

    private function handleCachingContainer(Container $container): void
    {
        if ($this->environment !== 'prod') {
            return;
        }

        $dumper = new PhpDumper($container);

        if ($this->context === 'http') {
            file_put_contents(
                $this->dumpFilePath,
                $dumper->dump(['class' => 'CachedContainer'])
            );
        }
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
