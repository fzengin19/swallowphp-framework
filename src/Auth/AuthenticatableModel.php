<?php

namespace SwallowPHP\Framework\Auth;

use SwallowPHP\Framework\Database\Model;
use SwallowPHP\Framework\Contracts\Auth\AuthenticatableInterface; // Add back
use SwallowPHP\Framework\Auth\AuthenticatableTrait; // Add back

/**
 * Base model class for authenticatable entities.
 * Provides default implementation via AuthenticatableTrait.
 */
abstract class AuthenticatableModel extends Model implements AuthenticatableInterface // Add back implements
{
    use AuthenticatableTrait; // Add back use

    // The trait provides the default implementations for:
    // getAuthIdentifierName()
    // getAuthIdentifier()
    // getAuthPassword()

    // Application-specific user models will extend this class.
    // They can override methods from the trait if needed.
}