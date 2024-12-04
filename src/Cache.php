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

        private const MAX_CACHE_SIZE = 52428800; // 50MB
        private const CACHE_PERMISSIONS = 0600;
        
        private static function loadCache()
        {
            if (!isset(self::$cacheFile)) {
                $cacheDir = dirname($_SERVER['DOCUMENT_ROOT'] . env('CACHE_FILE', '/../cache.json'));
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0750, true);
                }
                self::$cacheFile = $_SERVER['DOCUMENT_ROOT'] . env('CACHE_FILE', '/../cache.json');
            }

            if (!file_exists(self::$cacheFile)) {
                touch(self::$cacheFile);
                chmod(self::$cacheFile, self::CACHE_PERMISSIONS);
            }

            // File locking for concurrent access
            $fp = fopen(self::$cacheFile, 'r');
            if (flock($fp, LOCK_SH)) {
                $json = stream_get_contents($fp);
                flock($fp, LOCK_UN);
                fclose($fp);

                if (!empty($json)) {
                    $data = json_decode($json, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        self::$cache = $data;
                    } else {
                        error_log("Cache file corruption detected: " . json_last_error_msg());
                        self::$cache = [];
                    }
                }
            } else {
                error_log("Could not acquire shared lock for cache file");
                self::$cache = [];
            }
        }

        private static function saveCache()
        {
            // Check cache size before saving
            $cacheSize = strlen(json_encode(self::$cache));
            if ($cacheSize > self::MAX_CACHE_SIZE) {
                // Remove oldest entries until under limit
                while ($cacheSize > self::MAX_CACHE_SIZE && !empty(self::$cache)) {
                    reset(self::$cache);
                    $oldestKey = key(self::$cache);
                    unset(self::$cache[$oldestKey]);
                    $cacheSize = strlen(json_encode(self::$cache));
                }
            }

            $fp = fopen(self::$cacheFile, 'c+');
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                rewind($fp);
                $json = json_encode(self::$cache);
                fwrite($fp, $json);
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
            } else {
                error_log("Could not acquire exclusive lock for cache file");
                fclose($fp);
            }
        }

        public static function has($key)
        {
            try {
                self::loadCache();
                return is_array(self::$cache) && 
                       array_key_exists($key, self::$cache) && 
                       !self::isExpired($key);
            } catch (\Exception $e) {
                error_log("Cache error in has(): " . $e->getMessage());
                return false;
            }
        }

        public static function get($key)
        {
            try {
                self::loadCache();
                if (self::has($key)) {
                    $item = self::$cache[$key];
                    // Validate data structure
                    if (!isset($item['value'])) {
                        self::delete($key);
                        return null;
                    }
                    return $item['value'];
                }
                return null;
            } catch (\Exception $e) {
                error_log("Cache error in get(): " . $e->getMessage());
                return null;
            }
        }

        public static function set($key, $value, $expiration = null)
        {
            try {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException("Cache key must be a string");
                }
                
                self::loadCache();
                
                // Validate expiration
                if ($expiration !== null && (!is_int($expiration) || $expiration < time())) {
                    throw new \InvalidArgumentException("Invalid expiration time");
                }

                self::$cache[$key] = [
                    'value' => $value,
                    'expiration' => $expiration,
                    'created_at' => time()
                ];
                
                self::saveCache();
                return true;
            } catch (\Exception $e) {
                error_log("Cache error in set(): " . $e->getMessage());
                return false;
            }
        }

        public static function delete($key)
        {
            try {
                self::loadCache();
                if (array_key_exists($key, self::$cache)) {
                    unset(self::$cache[$key]);
                    self::saveCache();
                    return true;
                }
                return false;
            } catch (\Exception $e) {
                error_log("Cache error in delete(): " . $e->getMessage());
                return false;
            }
        }

        public static function clear()
        {
            try {
                self::$cache = [];
                self::saveCache();
                return true;
            } catch (\Exception $e) {
                error_log("Cache error in clear(): " . $e->getMessage());
                return false;
            }
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
