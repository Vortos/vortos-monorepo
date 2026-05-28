<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Middleware;

use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Executes the ordered middleware chain and then the underlying transport driver.
 *
 * The MiddlewareCompilerPass populates $middlewares with services tagged
 * 'vortos_aws_ses.email_middleware', sorted by priority (highest first).
 *
 * Calling send() builds a closure chain at runtime — each middleware wraps
 * its successor — and invokes it from the outermost (highest-priority) layer.
 */
final class EmailMiddlewareStack implements MailerInterface
{
    /** @param EmailMiddlewareInterface[] $middlewares Sorted highest-priority first. */
    public function __construct(
        private readonly MailerInterface $driver,
        private readonly array $middlewares = [],
    ) {}

    public function send(Email $email): SentEmail
    {
        $chain = fn(Email $e) => $this->driver->send($e);

        foreach (array_reverse($this->middlewares) as $middleware) {
            $next  = $chain;
            $chain = static function (Email $e) use ($middleware, $next): SentEmail {
                return $middleware->process($e, $next);
            };
        }

        return $chain($email);
    }
}
