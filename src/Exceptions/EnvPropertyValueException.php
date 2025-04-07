<?php

namespace SwallowPHP\Framework\Exceptions;

use Exception;

class EnvPropertyValueException extends Exception
{

    /**
     * Constructs a new instance of the EnvPropertyNotAllowedException
     *
     * @param string $message the exception message
     * @param int $code the exception code
     * @param Exception|null $previous the previous exception used for chaining
     */
    public function __construct($message = 'Invalid or missing environment variable configuration.', $code = 500, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
