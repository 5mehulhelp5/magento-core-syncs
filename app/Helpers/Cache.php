<?php
namespace MagentoSync\Helpers;

use MagentoSync\Helpers\Logger;

class Cache {
    protected static $dir = __DIR__ . '/../../runtime/cache/';

    public static function get($key) {
        $path = self::$dir . md5($key);
        if (file_exists($path) && (time() - filemtime($path)) < 60) {
            return unserialize(file_get_contents($path));
        }
        return null;
    }

    public static function set($key, $data) {
        if (!is_dir(self::$dir)) {
            if (!mkdir(self::$dir, 0777, true)) {
                Logger::log("Failed to create cache directory: " . self::$dir);
                return;
            }
        }

        $result = file_put_contents(self::$dir . md5($key), serialize($data));
        if ($result === false) {
            Logger::log("Failed to write cache for key: $key");
        }
    }
}
