<?php 
namespace SwallowPHP\Framework\Exceptions;

use Exception;

class RateLimitExceededException extends Exception{
    public function __construct($message = 'Too Many Requests', $code = 429, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}