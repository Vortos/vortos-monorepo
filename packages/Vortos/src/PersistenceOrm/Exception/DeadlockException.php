<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/** The database detected a deadlock and aborted one of the transactions. Safe to retry. */
class DeadlockException extends ConcurrencyException {}
