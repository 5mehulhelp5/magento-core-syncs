<?php
namespace MagentoSync\Helpers;

class Logger {
    public static function log($message) {
        file_put_contents(dirname(__DIR__, 2) . '/logs/magento-sync.log', date('c') . " - $message\n", FILE_APPEND);
    }
}