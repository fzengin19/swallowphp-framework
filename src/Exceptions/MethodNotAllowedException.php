<?php

namespace Framework\Exceptions;

use Exception;

class MethodNotAllowedException extends Exception
{


    /**
     * Constructor for the class.
     *
     * @param string $message The error message. Default is 'Env Property Is Not Allowed'.
     * @param int $code The error code. Default is 519.
     * @param Exception $previous The previous exception. Default is null.
     * @throws Exception When an error occurs.
     * @return void
     */
    public function __construct($message = 'Method Not Allowed', $code = 405, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
