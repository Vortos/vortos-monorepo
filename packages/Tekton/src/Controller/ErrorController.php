<?php

namespace Fortizan\Tekton\Controller;

use Fortizan\Tekton\Contract\PublicExceptionInterface;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErrorController
{

    public function __construct(
        private bool $debug,
        private ?LoggerInterface $logger = null
    ) {}

    public function __invoke(\Throwable $exception, Request $request): Response
    {
        $this->logException($exception, $request);

        $statusCode = $this->getStatusCode($exception);
        
        $message = $this->getMessage($exception, $statusCode);

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'error' => true,
                'code' => $statusCode,
                'message' => $message,
                'trace' => $this->debug ? $exception->getTrace() : []
            ], $statusCode);
        }

        $viewData = [
            'statusCode'  => $statusCode,
            'message'     => $message,
            'exception'   => $exception,
            'isDebug'     => $this->debug,
            'codeSnippet' => $this->getCodeSnippet($exception)
        ];

        ob_start();
        extract($viewData, EXTR_SKIP);
        include __DIR__ . '/../View/error.html.php';
        $content = ob_get_clean();

        return new Response($content, $statusCode);
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
        if($exception instanceof HttpExceptionInterface){

            if($exception->getStatusCode() >= 500){
                return Level::Critical;
            }

            if($exception->getStatusCode() >= 400){
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
