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

    /** @var array<class-string, int|null> Null means "the class declares no #[HttpStatus]". */
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
        // A DomainError with no attribute means "a rule was broken" and nothing more
        // specific, which is what 422 says.
        return $this->declaredStatus($error) ?? 422;
    }

    /**
     * The status an exception class declares via #[HttpStatus], or null if it declares none.
     *
     * ## Why this is not limited to DomainError
     *
     * An application's own exception hierarchy is usually older than its first DomainError,
     * and the common shape is a named class extending \DomainException — which carries no
     * status at all. Such an exception reaching here took the generic branch: status 500,
     * and at 500 {@see getMessage()} replaces the message with "Something went wrong,
     * please try again later." So a class whose whole purpose was to explain a refusal had
     * its explanation discarded at the last step, and was logged as CRITICAL alongside real
     * outages.
     *
     * That is invisible from the outside. The endpoint answers, the client shows a toast,
     * nothing errors, and the only symptom is a user who cannot discover what is wrong.
     * It stays invisible while the exception is caught by the controller that knows about
     * it, and appears the day a second route throws the same exception without catching it.
     *
     * Reading the attribute off ANY throwable makes the declaration the single place the
     * status lives, and makes adding one a one-line change to the exception rather than a
     * reparenting of a hierarchy that catch blocks and tests already depend on.
     *
     * Cached per class because this runs on an error path in a long-lived worker, and
     * reflection on a hot 404 route is not free.
     */
    private function declaredStatus(\Throwable $exception): ?int
    {
        $class = $exception::class;

        if (!array_key_exists($class, self::$httpStatusCache)) {
            $attrs = (new \ReflectionClass($class))->getAttributes(
                HttpStatus::class,
                \ReflectionAttribute::IS_INSTANCEOF,
            );

            self::$httpStatusCache[$class] = $attrs === []
                ? null
                : $attrs[0]->newInstance()->status;
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

        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : $this->declaredStatus($exception);

        // Unknown status means an unplanned failure, and Critical is the right default for
        // one. A DECLARED 4xx is a rule the application meant to enforce, and logging that
        // at Critical is how an ordinary refusal — a closed form, an expired invite — ends
        // up indistinguishable from an outage on a dashboard.
        if ($status === null) {
            return Level::Critical;
        }

        if ($status >= 500) {
            return Level::Critical;
        }

        return $status >= 400 ? Level::Error : Level::Critical;
    }

    private function getStatusCode(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        // An HttpExceptionInterface already states its status, so it wins. Anything else
        // may still declare one via #[HttpStatus] — see declaredStatus().
        return $this->declaredStatus($exception) ?? 500;
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
