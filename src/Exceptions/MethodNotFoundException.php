<?php

namespace SwallowPHP\Framework\Exceptions;

use Exception;

class MethodNotFoundException extends Exception
{

    /**
     * Constructs a new instance of the MethodNotFoundException
     *
     * @param string $message the exception message
     * @param int $code the exception code
     * @param Exception|null $previous the previous exception used for chaining
     */
    public function __construct($message = 'Method Not Found', $code = 404, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
