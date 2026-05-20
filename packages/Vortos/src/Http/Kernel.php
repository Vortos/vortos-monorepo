<?php

declare(strict_types=1);

namespace Vortos\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vortos\Http\Contract\ExceptionHandlerInterface;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Controller\ArgumentResolver;
use Vortos\Http\Controller\ControllerResolver;
use Vortos\Http\Pipeline\Pipeline;
use Vortos\Http\Routing\Router;

final class Kernel
{
    /**
     * @param ExceptionHandlerInterface[]    $exceptionHandlers  Ordered by priority (highest = first to handle)
     * @param TerminableMiddlewareInterface[] $terminableMiddleware
     */
    public function __construct(
        private readonly Router $router,
        private readonly ControllerResolver $controllerResolver,
        private readonly ArgumentResolver $argumentResolver,
        private readonly Pipeline $pipeline,
        private readonly RequestStack $requestStack,
        private readonly array $exceptionHandlers = [],
        private readonly array $terminableMiddleware = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(Request $request, bool $isSubRequest = false): SymfonyResponse
    {
        $obLevel = ob_get_level();
        $this->requestStack->push($request);

        try {
            if ($isSubRequest) {
                return $this->handleSubRequest($request);
            }

            $this->router->match($request);

            $callable = $this->controllerResolver->resolve($request);
            $request->attributes->set('_controller_callable', $callable);

            // Exceptions from the controller core are caught inside the pipeline so that
            // middleware after-phases (security headers, tracing, metrics) still run on
            // error responses. Exceptions from middleware itself bubble out of pipeline->run()
            // and are caught by the outer try/catch below.
            $response = $this->pipeline->run(
                $request,
                function (Request $req) use ($callable, $obLevel): SymfonyResponse {
                    try {
                        $args   = $this->argumentResolver->resolve($req, $callable);
                        $result = $callable(...$args);

                        if (!$result instanceof SymfonyResponse) {
                            throw new \LogicException(sprintf(
                                'Controller "%s" must return a Response, got "%s".',
                                $this->describeCallable($callable),
                                get_debug_type($result),
                            ));
                        }

                        return $result;
                    } catch (\Throwable $e) {
                        while (ob_get_level() > $obLevel) {
                            ob_end_clean();
                        }
                        // Convert to response inside pipeline so after-phases see the error response
                        return $this->handleException($e, $req);
                    }
                },
            );

            return $this->finalizeResponse($response, $request);

        } catch (\Throwable $e) {
            // Middleware itself threw — no after-phases available, handle directly
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            return $this->handleException($e, $request);

        } finally {
            $this->requestStack->pop();
        }
    }

    public function terminate(Request $request, SymfonyResponse $response): void
    {
        foreach ($this->terminableMiddleware as $middleware) {
            $middleware->terminate($request, $response);
        }
    }

    private function handleSubRequest(Request $request): SymfonyResponse
    {
        $request->attributes->set('_sub_request', true);
        $this->router->match($request);

        $callable = $this->controllerResolver->resolve($request);
        $args     = $this->argumentResolver->resolve($request, $callable);
        $result   = $callable(...$args);

        if (!$result instanceof SymfonyResponse) {
            throw new \LogicException('Sub-request controller must return a Response.');
        }

        return $this->finalizeResponse($result, $request);
    }

    private function handleException(\Throwable $e, Request $request): SymfonyResponse
    {
        try {
            foreach ($this->exceptionHandlers as $handler) {
                $response = $handler->handle($e, $request);
                if ($response !== null) {
                    return $this->finalizeResponse($response, $request);
                }
            }

            // No handler matched — this should not happen if ErrorController is registered
            return $this->finalizeResponse(
                new Response('Internal Server Error', 500),
                $request,
            );

        } catch (\Throwable $double) {
            $this->logger->critical('Exception handler threw during exception handling', [
                'original'  => ['class' => $e::class, 'message' => $e->getMessage()],
                'secondary' => ['class' => $double::class, 'message' => $double->getMessage()],
            ]);

            return new Response('Internal Server Error', 500);
        }
    }

    private function finalizeResponse(SymfonyResponse $response, Request $request): SymfonyResponse
    {
        if ($response instanceof StreamedResponse) {
            $original = $response->getCallback();
            $stack    = $this->requestStack;
            $response->setCallback(static function () use ($request, $original, $stack): void {
                $stack->push($request);
                try {
                    $original();
                } finally {
                    $stack->pop();
                }
            });
        }

        $response->prepare($request);

        return $response;
    }

    private function describeCallable(callable $callable): string
    {
        if (is_array($callable)) {
            $class = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];
            return $class . '::' . $callable[1];
        }

        if (is_object($callable)) {
            return $callable::class . '::__invoke';
        }

        return (string) $callable;
    }
}
