<?php

namespace SwallowPHP\Framework;

use SwallowPHP\Framework\Model;

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
        
        $hashedPassword = password_hash($password, PASSWORD_ARGON2ID);
        $existingUser = Model::table('users')->where('email', '=', $email)->first();

        // Kullanıcı zaten varsa
        if ($existingUser && $existingUser->id) {
            return false;
        }
        
        $user = Model::table('users')->create([
            'email' => $email,
            'password' => $hashedPassword,
            'role' => $role,
            'remember_token' => static::generateToken() // Yeni kullanıcı için token oluştur
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
        Cookie::delete('remember_token');
        Cookie::delete('user_id');
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
        if (empty($email) || empty($password)) {
            return false;
        }

        $user = Model::table('users')->where('email', '=', $email)->first();
        
        if (!$user || !password_verify($password, $user->password)) {
            return false;
        }

        $expire_days = $remember ? 30 : 1;
        
        Cookie::set('remember_token', $user->remember_token, $expire_days);
        Cookie::set('user_id', $user->id, $expire_days);
        Cookie::set('user', $user->toArray(), $expire_days);
        
        return true;
    }

    /**
     * Check if the user is authenticated and invalidate the session if user data has changed.
     *
     * @return bool Returns true if the user is authenticated, false otherwise.
     */
    public static function isAuthenticated()
    {
        if (!Cookie::has('user_id') || !Cookie::has('user') || !Cookie::has('remember_token')) {
            static::logout();
            return false;
        }

        $cookieUser = Cookie::get('user');
        $userId = Cookie::get('user_id');
        $rememberToken = Cookie::get('remember_token');

        if (!$cookieUser || !$userId || !$rememberToken) {
            static::logout();
            return false;
        }

        $user = Model::table('users')->where('id', '=', $userId)->first();
        
        if (!$user || $user->remember_token !== $rememberToken) {
            static::logout();
            return false;
        }

        // Kullanıcı verilerini güncelle
        Cookie::set('user', $user->toArray(), 30);
        return true;
    }
    public static function logoutOtherSessions()
    {
        $user = static::user();
        $user->remember_token = static::generateToken();
        $user->save();
        $expire_days = 30;
        Cookie::set('remember_token', $user->remember_token, $expire_days);
        Cookie::set('user_id', $user->id, $expire_days);
        Cookie::set('user', $user->toArray(), $expire_days);

    }

    public static function generateToken()
    {
        return bin2hex(random_bytes(32));
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
