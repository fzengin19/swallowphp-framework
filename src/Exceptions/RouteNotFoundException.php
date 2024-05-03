<?php 
namespace Framework\Exceptions;

use Exception;

class RouteNotFoundException extends Exception
{
    public function __construct($message = 'Route Not Found', $code = 404, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}





