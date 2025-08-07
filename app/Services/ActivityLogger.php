<?php
namespace MagentoSync\Services;

use MagentoSync\Models\ActivityLog;
use MagentoSync\Helpers\Logger;

class ActivityLogger {
    public static function logDb(string $username, string $actionType, string $itemName): void {
        try {
            ActivityLog::create([
                'username' => $username,
                'name' => $username,
                'admin_id' => 1,
                'store_id' => 0,
                'scope' => 'global',
                'action_type' => ucfirst($actionType),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'forwarded_ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'module' => 'MagentoSync',
                'fullaction' => 'magento_sync.importer',
                'item_name' => $itemName,
                'item_url' => '',
                'is_revertable' => 0,
                'revert_by' => '',
            ]);
        } catch (\Exception $e) {
            Logger::log("ActivityLogger DB fail: ".$e->getMessage());
        }
    }
}