<?php

namespace Framework;

use Framework\Model;

class Auth
{

    /**
     * Register a user with the given email and password.
     *
     * @param string $email The email of the user.
     * @param string $password The password of the user.
     * @return User|false The created user object or false if registration failed.
     */
    public static function register($email, $password, $role = 'member')
    {
        
        if (strlen($password) < 3) {
            return false;
        }
        $hashedPassword = password_hash($password, PASSWORD_ARGON2I);
        $user = Model::table('users')->where('email', '=', $email)->first();

        if (null != $user->id) {
            return false;
        }
        $user = Model::table('users')->create([
            'email' => $email,
            'password' => $hashedPassword,
            'role' => $role
        ]);
        return $user;
    }
    /**
     * Logout the user by deleting the 'user' cookie.
     *
     */
    public static function logout()
    {
        Cookie::delete('user');
    }
    /**
     * Authenticates a user with the given email and password.
     *
     * @param string $email The email of the user.
     * @param string $password The password of the user.
     * @param bool $remember (Optional) Whether to remember the user or not. Default is false.
     * @return bool Returns true if authentication is successful, false otherwise.
     */
    public static function authenticate($email, $password, $remember = false)
    {
        $user = Model::table('users')->where('email', '=', $email)->first();
        if ($user && password_verify($password, $user->password)) {
            if ($remember) {
                Cookie::set('remember', 'true', 30);
                Cookie::set('user', $user->toArray(), 30);
            } else
                Cookie::set('user', $user->toArray());
            return true;
        }
        return false;
    }

    /**
     * Check if the user is authenticated and invalidate the session if user data has changed.
     *
     * @return bool Returns true if the user is authenticated, false otherwise.
     */
    public static function isAuthenticated()
    {
        if (Cookie::has('user')) {

            $cookieUser = Cookie::get('user');
            if ($cookieUser == null) {
                return false;
            }
            $dbUser = Model::table('users')->where('id', '=', $cookieUser['id'])->first();
            if ($dbUser !== null) {
                if ($cookieUser != $dbUser->toArray()) {
                    Cookie::delete('user');
                    return false;
                }
            } else {

                Cookie::delete('user');
                return false;
            }
            if (Cookie::get('remember') == 'true') {

                Cookie::set('user', $dbUser->toArray(), 30);
            }
            return true;
        }


        return false;
    }


    /**
     * Retrieves the user object if the user is authenticated.
     *
     * @return User|bool The user object if authenticated, false otherwise.
     */
    public static function user()
    {
        if (static::isAuthenticated()) {
            $user = new Model();
            $user->table('users');
            $user->fill(Cookie::get('user'));
            return $user;
        }
        return false;
    }

    public static function isAdmin()
    {
        if (self::isAuthenticated() && self::user()->role == 'admin')
            return true;
        return false;
    }
}
