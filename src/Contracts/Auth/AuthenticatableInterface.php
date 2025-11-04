<?php

namespace SwallowPHP\Framework\Contracts\Auth;

interface AuthenticatableInterface
{
    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName(): string;

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier(): mixed;

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword(): string;

    /**
     * Get the "remember me" token value.
     *
     * @return string|null
     */
    public function getRememberToken(): ?string;

    /**
     * Set the "remember me" token value.
     *
     * @param  string|null  $value
     * @return void
     */
    public function setRememberToken(?string $value): void;

    /**
     * Get the name of the "remember me" token column.
     *
     * @return string
     */
    public function getRememberTokenName(): string;
}
