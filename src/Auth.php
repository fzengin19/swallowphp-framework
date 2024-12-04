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
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Enhanced password validation
        if (strlen($password) < 8 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)) {
            return false;
        }

        // Check if user already exists
        $user = Model::table('users')->where('email', '=', $email)->first();
        if ($user && $user->id) {
            return false;
        }

        // Hash password with strong options
        $hashedPassword = password_hash(
            $password,
            PASSWORD_ARGON2ID,
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3]
        );

        // Create user with sanitized input
        $user = Model::table('users')->create([
            'email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'password' => $hashedPassword,
            'role' => in_array($role, ['member', 'admin']) ? $role : 'member'
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
    private static $loginAttempts = [];
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 minutes

    public static function authenticate($email, $password, $remember = false)
    {
        // Check login attempts
        $ip = $_SERVER['REMOTE_ADDR'];
        $now = time();
        
        if (isset(self::$loginAttempts[$ip])) {
            $attempt = self::$loginAttempts[$ip];
            if ($attempt['count'] >= self::MAX_LOGIN_ATTEMPTS && 
                $now - $attempt['time'] < self::LOCKOUT_TIME) {
                return false;
            }
            if ($now - $attempt['time'] >= self::LOCKOUT_TIME) {
                unset(self::$loginAttempts[$ip]);
            }
        }

        $user = Model::table('users')->where('email', '=', $email)->first();
        if ($user && password_verify($password, $user->password)) {
            // Reset login attempts on successful login
            unset(self::$loginAttempts[$ip]);

            // Generate session token
            $token = bin2hex(random_bytes(32));
            $userData = $user->toArray();
            $userData['session_token'] = $token;

            // Set secure cookies
            $cookieOptions = [
                'expires' => $remember ? time() + (86400 * 30) : 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ];

            Cookie::set('user', $userData, $cookieOptions);
            if ($remember) {
                Cookie::set('remember', 'true', $cookieOptions);
            }

            return true;
        }

        // Track failed login attempts
        if (!isset(self::$loginAttempts[$ip])) {
            self::$loginAttempts[$ip] = ['count' => 0, 'time' => $now];
        }
        self::$loginAttempts[$ip]['count']++;
        self::$loginAttempts[$ip]['time'] = $now;

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
