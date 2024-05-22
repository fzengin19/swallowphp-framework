<?php

namespace SwallowPHP\Framework;

use Exception;
use PDO;
use SwallowPHP\Framework\Env;
use SwallowPHP\Framework\Exceptions\EnvPropertyValueException;

if (env('CACHE_DRIVER', 'FILE') == 'FILE') {

    class Cache
    {
        private static $cacheFile;
        private static $cache = array();

        private static function loadCache()
        {
            if (!isset(self::$cacheFile))
                self::$cacheFile = $_SERVER['DOCUMENT_ROOT'] . env('CACHE_FILE', 'cache.json');
            if (file_exists(self::$cacheFile)) {
                $json = file_get_contents(self::$cacheFile);
                $data = json_decode($json, true);
                if (is_array($data));
                self::$cache = $data;
            }
        }

        private static function saveCache()
        {
            $json = json_encode(self::$cache);
            file_put_contents(self::$cacheFile, $json);
        }

        public static function has($key)
        {
            self::loadCache();
            if (is_array(self::$cache) && array_key_exists($key, self::$cache) && !self::isExpired($key))
                return true;
            return false;
        }

        public static function get($key)
        {
            self::loadCache();
            if (self::has($key) && !self::isExpired($key)) {
                return self::$cache[$key]['value'];
            } else {
                self::delete($key);
                return null;
            }
        }

        public static function set($key, $value, $expiration = null)
        {
            self::loadCache();
            self::$cache[$key] = array('value' => $value, 'expiration' => $expiration);
            self::saveCache();
        }

        public static function delete($key)
        {
            self::loadCache();
            unset(self::$cache[$key]);
            self::saveCache();
        }

        public static function clear()
        {
            self::$cache = array();
            self::saveCache();
        }

        private static function isExpired($key)
        {
            if (!array_key_exists($key, self::$cache))
                return true;

            $expiration = self::$cache[$key]['expiration'];
            return $expiration !== null && time() >= $expiration;
        }
    }
} else if (env('CACHE_DRIVER', 'FILE') == 'SQLITE') {

    class Cache
    {
        private static $db;

        private static function connect()
        {
            if (self::$db === null) {
                self::$db = new PDO('sqlite:../cache.db');
                self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$db->exec('CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)');
            }
        }

        public static function has($key)
        {
            self::connect();
            $stmt = self::$db->prepare('SELECT * FROM cache WHERE key=?');
            $stmt->execute(array($key));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && !self::isExpired($result['expiration'])) {
                return true;
            } else {
                return false;
            }
        }

        public static function get($key)
        {
            self::connect();
            $stmt = self::$db->prepare('SELECT * FROM cache WHERE key=?');
            $stmt->execute(array($key));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (isset($result['key']) && !self::isExpired($result['expiration'])) {
                return unserialize($result['value']);
            } else {
                self::delete($key);
                return null;
            }
        }

        public static function set($key, $value, $expiration = null)
        {
            self::connect();
            $stmt = self::$db->prepare('INSERT OR REPLACE INTO cache (key, value, expiration) VALUES (?, ?, ?)');
            $stmt->execute(array(
                $key,
                serialize($value),
                $expiration
            ));
        }

        public static function delete($key)
        {
            self::connect();
            $stmt = self::$db->prepare('DELETE FROM cache WHERE key = :key');
            $stmt->execute(array('key' => $key));
        }

        public static function clear()
        {
            self::connect();
            self::$db->exec('DELETE FROM cache');
        }

        private static function isExpired($expiration)
        {
            if ($expiration !== null && time() >= $expiration) {
                return true;
            } else {
                return false;
            }
        }
    }
} else
    throw new EnvPropertyValueException();
