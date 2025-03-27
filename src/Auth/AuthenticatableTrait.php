<?php

namespace SwallowPHP\Framework\Auth;

/**
 * Provides default implementation for the AuthenticatableInterface.
 * Assumes the model has 'id' and 'password' attributes.
 */
trait AuthenticatableTrait
{
    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName(): string
    {
        // Assumes the primary key is 'id'. Override in model if different.
        return 'id';
    }

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->{$this->getAuthIdentifierName()};
    }

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword(): string
    {
        // Assumes the password column is 'password'. Override in model if different.
        // Ensure the property exists or handle appropriately
        return $this->password ?? '';
    }

    // Remember token methods can be added here if needed later
    // public function getRememberToken(): ?string { ... }
    // public function setRememberToken(?string $value): void { ... }
    // public function getRememberTokenName(): string { ... }
}