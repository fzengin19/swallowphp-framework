<?php 
namespace SwallowPHP\Framework\Exceptions;

use Exception;

class AuthorizationException extends Exception
{
    public function __construct($message = 'Access Denied: You are not authorized to perform this action.', $code = 401, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}





