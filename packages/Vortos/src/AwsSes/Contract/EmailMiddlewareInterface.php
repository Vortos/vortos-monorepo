<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Email middleware contract.
 *
 * Middleware wraps the send pipeline. Each middleware calls $next($email) to
 * pass control to the next middleware in the stack. The final middleware in
 * the chain calls the underlying MailerInterface driver.
 *
 * Registration: tag your class with #[AsEmailMiddleware(priority: N)].
 * Higher priority runs first. Framework middleware occupies 500–1000.
 * Application middleware should use priorities 1–499.
 */
interface EmailMiddlewareInterface
{
    public function process(Email $email, callable $next): SentEmail;
}
