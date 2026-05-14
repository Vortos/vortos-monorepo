<?php

declare(strict_types=1);

namespace Vortos\Messaging\Exception;

/**
 * Thrown by ConsumerRunner when DeadLetterWriter fails to persist a failed message.
 * Signals that the Kafka offset must NOT be committed so the message is redelivered.
 */
final class DeadLetterWriteException extends \RuntimeException {}
