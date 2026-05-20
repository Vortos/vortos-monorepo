<?php

declare(strict_types=1);

namespace Vortos\Http\Controller;

use Vortos\Domain\Error\DomainError;
use Vortos\Domain\Error\HttpStatus;
use Vortos\Http\Contract\ExceptionHandlerInterface;
use Vortos\Http\Contract\PublicExceptionInterface;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Vortos\Http\Exception\HttpExceptionInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ErrorController implements ExceptionHandlerInterface
{

    public function __construct(
        private bool $debug,
        private ?LoggerInterface $logger = null
    ) {}

    public function handle(\Throwable $e, Request $request): ?Response
    {
        return $this->__invoke($e, $request);
    }

    /** @var array<class-string, int> */
    private static array $httpStatusCache = [];

    public function __invoke(\Throwable $exception, Request $request): Response
    {
        $this->logException($exception, $request);

        if ($exception instanceof DomainError) {
            return $this->handleDomainError($exception, $request);
        }

        $statusCode = $this->getStatusCode($exception);
        $message    = $this->getMessage($exception, $statusCode);
        $extraHeaders = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'error'   => true,
                'code'    => $statusCode,
                'message' => $message,
                'trace'   => $this->debug ? $this->safeTrace($exception) : [],
            ], $statusCode, $extraHeaders);
        }

        $isDebug     = $this->debug;
        $codeSnippet = $this->getCodeSnippet($exception);

        ob_start();
        include __DIR__ . '/../View/error.html.php';
        $content = ob_get_clean();

        return new Response($content, $statusCode, $extraHeaders);
    }

    private function handleDomainError(DomainError $error, Request $request): Response
    {
        $status = $this->resolveDomainErrorStatus($error);

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'error'   => true,
                'code'    => $error->errorCode(),
                'message' => $error->getMessage(),
                'context' => $error->context(),
                'trace'   => $this->debug ? $this->safeTrace($error) : [],
            ], $status);
        }

        $isDebug     = $this->debug;
        $statusCode  = $status;
        $message     = $error->getMessage();
        $codeSnippet = $this->getCodeSnippet($error);

        ob_start();
        include __DIR__ . '/../View/error.html.php';
        $content = ob_get_clean();

        return new Response($content, $status);
    }

    private function resolveDomainErrorStatus(DomainError $error): int
    {
        $class = $error::class;

        if (!isset(self::$httpStatusCache[$class])) {
            $attrs = (new \ReflectionClass($class))->getAttributes(HttpStatus::class);
            self::$httpStatusCache[$class] = empty($attrs) ? 422 : $attrs[0]->newInstance()->status;
        }

        return self::$httpStatusCache[$class];
    }

    private function logException(\Throwable $exception, Request $request): void
    {
        $level = $this->resolveLogLevel($exception);

        if ($this->logger) {
            $this->logger->log($level, $exception->getMessage(), [
                'exception' => $exception,
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod()
            ]);

            return;
        }

        error_log(sprintf(
            "[CRITICAL STARTUP ERROR] %s in %s:%d Trace: %s",
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ));
    }

    private function resolveLogLevel(\Throwable $exception): Level
    {
        if ($exception instanceof DomainError) {
            return $this->resolveDomainErrorStatus($exception) >= 500 ? Level::Critical : Level::Error;
        }

        if ($exception instanceof HttpExceptionInterface) {
            if ($exception->getStatusCode() >= 500) {
                return Level::Critical;
            }

            if ($exception->getStatusCode() >= 400) {
                return Level::Error;
            }
        }

        return Level::Critical;
    }

    private function getStatusCode(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
        } else {
            $statusCode = 500;
        }

        return $statusCode;
    }

    private function getMessage(\Throwable $exception, int $statusCode): string
    {
        if ($this->debug) {
            $message = $exception->getMessage();
        } else {
            if ($exception instanceof PublicExceptionInterface || $statusCode < 500) {
                $message = $exception->getMessage();
            } else {
                $message = 'Something went wrong, please try again later.';
            }
        }

        return $message;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->headers->get('Content-Type') === 'application/json'
            || $request->headers->get('Accept') === 'application/json';
    }

    private function safeTrace(\Throwable $e): array
    {
        return array_map(
            static fn(array $frame): array => array_diff_key($frame, ['args' => true]),
            $e->getTrace()
        );
    }

    private function getCodeSnippet(\Throwable $exception): array
    {
        if (!$this->debug || !file_exists($exception->getFile())) {
            return [];
        }

        $file = file($exception->getFile());

        $start = max(0, $exception->getLine() - 5);
        $limit = 10;

        $codeSnippet = array_slice($file, $start, $limit, true);

        return $codeSnippet;
    }
}
