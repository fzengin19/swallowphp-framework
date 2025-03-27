<?php

namespace SwallowPHP\Framework\Exceptions;

use Exception;

class CsrfTokenMismatchException extends Exception
{
    public function __construct($message = 'CSRF token mismatch', $code = 419, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
    