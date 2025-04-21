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

    /**
     * Get the column name for the "remember me" token.
     *
     * @return string
     */
    public function getRememberTokenName(): string
    {
        // Assumes the column name is 'remember_token'. Override in model if different.
        return 'remember_token';
    }

    /**
     * Get the current value of the "remember me" token.
     *
     * @return string|null
     */
    public function getRememberToken(): ?string
    {
        $tokenName = $this->getRememberTokenName();
        // Access the attribute using the model's magic __get method
        // which correctly retrieves from the $attributes array and applies casts.
        return $this->__get($tokenName);
        // Alternative (less ideal as it bypasses casting):
        // return $this->attributes[$tokenName] ?? null;
    }

    /**
     * Set the "remember me" token value.
     *
     * @param  string|null  $value
     * @return void
     */
    public function setRememberToken(?string $value): void
    {
        $tokenName = $this->getRememberTokenName();
        // Ensure the property exists or handle appropriately if the model uses magic methods
        $this->{$tokenName} = $value;
    }
}
