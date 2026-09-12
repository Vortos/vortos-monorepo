<?php

declare(strict_types=1);

namespace Vortos\Http\Tests;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Vortos\Domain\Error\HttpStatus;
use Vortos\Http\Controller\ErrorController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;

/**
 * An exception that declares #[HttpStatus] gets that status, whatever it extends.
 *
 * ## The bug this closes
 *
 * `ErrorController` read `#[HttpStatus]` only off a {@see \Vortos\Domain\Error\DomainError}.
 * Every other exception took the generic branch — status 500 — and at 500 the message is
 * deliberately replaced with "Something went wrong, please try again later." before it
 * leaves the server, because a 5xx message is an exception string that tells the user
 * nothing and a reader more than they should see.
 *
 * The common shape in an application older than its first DomainError is a named class
 * extending `\DomainException`: `InvitationExpiredException`, `LastAdminException`,
 * `AccountAlreadyClaimedException`. Each was written to explain a refusal, and each had
 * its explanation discarded at the last step and was logged CRITICAL next to real outages.
 *
 * It is invisible while the controller that throws such an exception also catches it. It
 * appears the day a second route throws the same one without a catch — which is why a
 * per-controller catch audit is not the fix, and why the status belongs on the exception.
 */
final class ErrorControllerDeclaredStatusTest extends TestCase
{
    public function testDeclaredStatusIsUsedForANonDomainErrorException(): void
    {
        $response = $this->handle(new DeclaredConflictException('This form is closed.'));

        self::assertSame(409, $response->getStatusCode());
    }

    /**
     * The half that actually reached users: a declared 4xx keeps its own message, where a
     * 500 would have replaced it with the generic sentence.
     */
    public function testDeclaredStatusPreservesTheMessageOutsideDebug(): void
    {
        $response = $this->handle(new DeclaredConflictException('This form is closed.'));
        $body     = $this->body($response);

        self::assertSame('This form is closed.', $body['message']);
        self::assertSame(409, $body['code']);
    }

    public function testAnUndeclaredExceptionStillGetsFiveHundredAndTheGenericMessage(): void
    {
        $response = $this->handle(new \DomainException('Column "foo" does not exist'));
        $body     = $this->body($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Something went wrong, please try again later.', $body['message']);
    }

    /** An explicit status on the exception must not override an HttpException's own. */
    public function testHttpExceptionStatusWinsOverTheAttribute(): void
    {
        $response = $this->handle(new NotFoundException('No such form.'));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * A declared 4xx logs at Error, not Critical.
     *
     * The reason this is asserted separately: the status and the log level were resolved by
     * two different methods, so fixing the response alone would have left every closed form
     * and expired invitation paging somebody.
     */
    public function testDeclaredFourHundredsLogAtErrorRatherThanCritical(): void
    {
        $logger = new RecordingLogger();
        $this->handle(new DeclaredConflictException('This form is closed.'), $logger);

        self::assertSame([Level::Error->value], $logger->levels);
    }

    public function testUndeclaredExceptionsStillLogAtCritical(): void
    {
        $logger = new RecordingLogger();
        $this->handle(new \DomainException('the database is gone'), $logger);

        self::assertSame([Level::Critical->value], $logger->levels);
    }

    /** A declared 5xx is a real failure and keeps both the generic message and Critical. */
    public function testDeclaredFiveHundredKeepsCriticalAndTheGenericMessage(): void
    {
        $logger   = new RecordingLogger();
        $response = $this->handle(new DeclaredServerException('internals'), $logger);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame([Level::Critical->value], $logger->levels);
        self::assertSame(
            'Something went wrong, please try again later.',
            $this->body($response)['message'],
        );
    }

    private function handle(\Throwable $e, ?RecordingLogger $logger = null): \Symfony\Component\HttpFoundation\Response
    {
        $controller = new ErrorController(debug: false, logger: $logger ?? new RecordingLogger());

        $request = Request::create('/api/forms/1/publish', 'POST');
        $request->headers->set('Accept', 'application/json');

        return $controller->handle($e, $request)
            ?? self::fail('ErrorController returned no response.');
    }

    /** @return array<string,mixed> */
    private function body(\Symfony\Component\HttpFoundation\Response $response): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

#[HttpStatus(409)]
final class DeclaredConflictException extends \DomainException {}

#[HttpStatus(503)]
final class DeclaredServerException extends \RuntimeException {}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<mixed> */
    public array $levels = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->levels[] = $level instanceof Level ? $level->value : $level;
    }
}
