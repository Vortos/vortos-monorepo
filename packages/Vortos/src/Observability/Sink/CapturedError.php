<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

use DateTimeImmutable;
use Throwable;

/**
 * A scrubbed, bounded record of an error on its way to an {@see ErrorSinkInterface}.
 *
 * PII / secret material is removed **by construction**: every factory runs the
 * message and context through {@see MessageScrubber} before the object exists, so
 * there is no path to build a `CapturedError` carrying a raw token or email. The
 * context is also size-bounded so a runaway payload can't blow up the error backend.
 */
final readonly class CapturedError
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const MAX_CONTEXT_KEYS = 50;

    /**
     * @param array<string, scalar> $context
     */
    private function __construct(
        public string $exceptionClass,
        public string $message,
        public string $fingerprint,
        public ErrorSeverity $severity,
        public array $context,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * @param array<array-key, mixed> $context
     */
    public static function fromThrowable(
        Throwable $throwable,
        ErrorSeverity $severity = ErrorSeverity::Error,
        array $context = [],
        ?MessageScrubber $scrubber = null,
        ?DateTimeImmutable $occurredAt = null,
    ): self {
        $scrubber ??= new MessageScrubber();

        $exceptionClass = $throwable::class;
        $message = self::truncate($scrubber->scrub($throwable->getMessage()));

        // Fingerprint groups identical errors WITHOUT carrying the (scrubbed) message:
        // class + originating file:line is stable across distinct PII-bearing messages.
        $fingerprint = hash('xxh128', $exceptionClass . '|' . $throwable->getFile() . ':' . $throwable->getLine());

        return new self(
            $exceptionClass,
            $message,
            $fingerprint,
            $severity,
            self::boundContext($scrubber->scrubContext($context)),
            $occurredAt ?? new DateTimeImmutable(),
        );
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function fromMessage(
        string $message,
        ErrorSeverity $severity = ErrorSeverity::Error,
        array $context = [],
        ?MessageScrubber $scrubber = null,
        ?DateTimeImmutable $occurredAt = null,
    ): self {
        $scrubber ??= new MessageScrubber();
        $scrubbed = self::truncate($scrubber->scrub($message));

        return new self(
            'message',
            $scrubbed,
            hash('xxh128', 'message|' . $scrubbed),
            $severity,
            self::boundContext($scrubber->scrubContext($context)),
            $occurredAt ?? new DateTimeImmutable(),
        );
    }

    private static function truncate(string $message): string
    {
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return substr($message, 0, self::MAX_MESSAGE_LENGTH);
        }

        return $message;
    }

    /**
     * @param array<string, scalar> $context
     * @return array<string, scalar>
     */
    private static function boundContext(array $context): array
    {
        if (count($context) <= self::MAX_CONTEXT_KEYS) {
            return $context;
        }

        return array_slice($context, 0, self::MAX_CONTEXT_KEYS, true);
    }

    /**
     * @return array{exceptionClass:string, message:string, fingerprint:string, severity:string, context:array<string,scalar>, occurredAt:string}
     */
    public function toArray(): array
    {
        return [
            'exceptionClass' => $this->exceptionClass,
            'message' => $this->message,
            'fingerprint' => $this->fingerprint,
            'severity' => $this->severity->value,
            'context' => $this->context,
            'occurredAt' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
