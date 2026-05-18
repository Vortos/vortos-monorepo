<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/** Parent of all concurrency-related persistence failures. */
class ConcurrencyException extends PersistenceException {}
