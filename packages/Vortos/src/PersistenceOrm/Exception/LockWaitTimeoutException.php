<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/** A row-lock wait exceeded innodb_lock_wait_timeout. Safe to retry. */
class LockWaitTimeoutException extends ConcurrencyException {}
