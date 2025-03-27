<?php

namespace SwallowPHP\Framework\Exceptions;

use Exception;

class CsrfTokenMismatchException extends Exception
{
    public $code = 419; // Custom status code for CSRF errors
  
    public function __construct($message = 'CSRF token mismatch', $code = 419, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
    