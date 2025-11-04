<?php

namespace SwallowPHP\Framework\Auth;

use SwallowPHP\Framework\Database\Model;
use SwallowPHP\Framework\Contracts\Auth\AuthenticatableInterface;
use SwallowPHP\Framework\Auth\AuthenticatableTrait;

/**
 * Base model class for authenticatable entities.
 * Provides default implementation via AuthenticatableTrait.
 */
abstract class AuthenticatableModel extends Model implements AuthenticatableInterface
{
    use AuthenticatableTrait;

    // The trait provides the default implementations for:
    // getAuthIdentifierName()
    // getAuthIdentifier()
    // getAuthPassword()
    // getRememberTokenName()
    // getRememberToken()
    // setRememberToken()

    // Application-specific user models (e.g., App\Models\User)
    // will extend this class and can override trait methods if needed.
}
