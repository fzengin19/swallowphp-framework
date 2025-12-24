<?php

namespace SwallowPHP\Framework\Exceptions;

use Exception;

class PayloadTooLargeException extends Exception
{
    public function __construct(
        string $message = 'Payload Too Large.',
        int $code = 413,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}


