<?php

namespace Framework;

class Cookie
{
    public static function set($name, $value, $expiration = 1)
    {
        $expiration = time() + ($expiration * 24 * 60 * 60);
        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $iv = random_bytes(16); // Rastgele 16 bayt (128 bit) uzunluğunda IV oluştur
        $encryptedValue = static::encrypt($value, $iv); // Veriyi ve IV'yi şifrele

        setcookie($name, base64_encode($iv . $encryptedValue), $expiration, '/');
    }

    public static function get($name)
    {
        if (isset($_COOKIE[$name])) {
            $cookieValue = base64_decode($_COOKIE[$name]);
            $iv = substr($cookieValue, 0, 16); // İlk 16 bayt IV'dir
            $encryptedValue = substr($cookieValue, 16);
            $decryptedValue = static::decrypt($encryptedValue, $iv); // Şifreyi çöz
            return json_decode($decryptedValue, true);
        }
        return null;
    }

    public static function delete($name)
    {
        if (isset($_COOKIE[$name])) {
            unset($_COOKIE[$name]);
            setcookie($name, null, -1, '/');
        }
    }

    public static function has($name)
    {
        return isset($_COOKIE[$name]);
    }

    private static function encrypt($data, $iv)
    {
        return openssl_encrypt($data, 'aes-256-cbc', env('APP_KEY'), OPENSSL_RAW_DATA, $iv);
    }

    private static function decrypt($data, $iv)
    {
        return openssl_decrypt($data, 'aes-256-cbc', env('APP_KEY'), OPENSSL_RAW_DATA, $iv);
    }
}
