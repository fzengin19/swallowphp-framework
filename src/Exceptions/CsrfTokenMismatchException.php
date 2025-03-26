<?php

namespace SwallowPHP\Framework\Exceptions;

use Exception;

class CsrfTokenMismatchException extends Exception
{
    // Typically associated with a 419 HTTP status code,
    // but the ExceptionHandler can handle this.
    // A simple Exception is sufficient for now.
}