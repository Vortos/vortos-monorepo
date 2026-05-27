<?php

declare(strict_types=1);

namespace Vortos\Ses\Middleware;

use Vortos\Ses\Attribute\AsEmailMiddleware;
use Vortos\Ses\Contract\EmailMiddlewareInterface;
use Vortos\Ses\Contract\SuppressionListInterface;
use Vortos\Ses\Exception\SuppressionListException;
use Vortos\Ses\Suppression\OnSuppressed;
use Vortos\Ses\ValueObject\Email;
use Vortos\Ses\ValueObject\EmailAddress;
use Vortos\Ses\ValueObject\SentEmail;

/**
 * Blocks or filters sends to suppressed email addresses.
 *
 * Priority 600 — runs after hook observers (700) and before the driver, so that
 * suppression is the last gate before actual delivery.
 *
 * Behaviour per OnSuppressed config:
 *   throw  — SuppressionListException raised if any recipient is suppressed
 *   skip   — suppressed recipients are silently removed; if all recipients are
 *             removed the exception is raised anyway (no-recipient send is invalid)
 *   ignore — suppression list is not consulted
 */
#[AsEmailMiddleware(priority: 600)]
final class SuppressionCheckMiddleware implements EmailMiddlewareInterface
{
    public function __construct(
        private readonly SuppressionListInterface $suppressionList,
        private readonly OnSuppressed $onSuppressed,
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        if ($this->onSuppressed === OnSuppressed::Ignore) {
            return $next($email);
        }

        $suppressed = $this->findSuppressed($email->getAllRecipients());

        if ($suppressed === []) {
            return $next($email);
        }

        if ($this->onSuppressed === OnSuppressed::Throw) {
            throw SuppressionListException::forAddresses($suppressed);
        }

        // Skip mode: remove suppressed recipients
        $suppressedEmails = array_map(fn(EmailAddress $a) => $a->address(), $suppressed);
        $filtered         = $email->withFilteredRecipients($suppressedEmails);

        // If all recipients were suppressed there is nothing left to send
        if ($filtered->getAllRecipients() === []) {
            throw SuppressionListException::allRecipientsSuppressed($suppressed);
        }

        return $next($filtered);
    }

    /** @return EmailAddress[] */
    private function findSuppressed(array $recipients): array
    {
        return array_values(array_filter(
            $recipients,
            fn(EmailAddress $a) => $this->suppressionList->isSuppressed($a),
        ));
    }
}
