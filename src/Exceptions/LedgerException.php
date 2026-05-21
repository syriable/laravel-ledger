<?php

declare(strict_types=1);

namespace Syriable\Ledger\Exceptions;

use RuntimeException;

/**
 * Root exception for every error that originates inside the ledger engine.
 *
 * Every more specific ledger exception extends this class so consumers can
 * catch all package errors with a single catch block when they want to.
 */
abstract class LedgerException extends RuntimeException {}
