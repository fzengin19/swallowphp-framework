<?php

namespace SwallowPHP\Framework\Exceptions;

use Exception;

class AuthenticationLockoutException extends Exception
{
    // Typically associated with a 429 status code.
    protected $message = 'Too many login attempts. Account locked.';
    protected $code = 429;
}