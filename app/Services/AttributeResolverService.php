<?php
namespace MagentoSync\Services;

use MagentoSync\Models\Attribute;
use MagentoSync\Models\AttributeOption;
use MagentoSync\Models\AttributeOptionValue;
use MagentoSync\Helpers\Cache;
use MagentoSync\Helpers\Logger;
use Illuminate\Database\Capsule\Manager as DB;

class AttributeResolverService {
    protected static $cache = [];

    public static function resolve(string $code) {
        if (isset(self::$cache[$code])) return self::$cache[$code];

        // Resolve entity_type_id for catalog_product in a portable way
        $entityType = DB::table('eav_entity_type')->where('entity_type_code', 'catalog_product')->first();
        $entityTypeId = $entityType ? $entityType->entity_type_id : null;

        $attribute = DB::table('eav_attribute')
            ->where('attribute_code', $code)
            ->when($entityTypeId, function($q) use ($entityTypeId) {
                return $q->where('entity_type_id', $entityTypeId);
            })
            ->first();

        if (!$attribute) {
            Logger::log("Attribute not found: $code");
            return null;
        }

        self::$cache[$code] = (array)$attribute;
        Cache::set('attribute_' . $code, (array)$attribute);
        return (object) self::$cache[$code];
    }

    public static function resolveOption($attributeId, $label) {
        $label = trim($label);
        $key = "attr_{$attributeId}_option_" . md5($label);
        if ($cached = Cache::get($key)) return $cached;

        // Try to find existing option first
        $option = DB::table('eav_attribute_option_value')
            ->join('eav_attribute_option', 'eav_attribute_option.option_id', '=', 'eav_attribute_option_value.option_id')
            ->where('eav_attribute_option.attribute_id', $attributeId)
            ->where('eav_attribute_option_value.value', $label)
            ->select('eav_attribute_option.option_id')
            ->first();

        if ($option) {
            Cache::set($key, $option->option_id);
            return $option->option_id;
        }

        // We'll use a transaction to reduce race-window for concurrent option creation
        return DB::transaction(function() use ($attributeId, $label, $key) {
            // Double-check inside transaction
            $existing = DB::table('eav_attribute_option_value')
                ->join('eav_attribute_option', 'eav_attribute_option.option_id', '=', 'eav_attribute_option_value.option_id')
                ->where('eav_attribute_option.attribute_id', $attributeId)
                ->where('eav_attribute_option_value.value', $label)
                ->select('eav_attribute_option.option_id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                Cache::set($key, $existing->option_id);
                return $existing->option_id;
            }

            // Insert option parent
            $optionId = DB::table('eav_attribute_option')->insertGetId([
                'attribute_id' => $attributeId,
                'sort_order' => 0
            ]);

            DB::table('eav_attribute_option_value')->insert([
                'option_id' => $optionId,
                'store_id' => 0,
                'value' => $label
            ]);

            Logger::log("Created new option '$label' for attribute_id $attributeId");
            Cache::set($key, $optionId);
            return $optionId;
        });
    }

    public static function getBackendTable(string $backendType): ?string {
        $tableMap = [
            'varchar' => 'catalog_product_entity_varchar',
            'int' => 'catalog_product_entity_int',
            'text' => 'catalog_product_entity_text',
            'decimal' => 'catalog_product_entity_decimal',
            'datetime' => 'catalog_product_entity_datetime',
        ];

        if (!isset($tableMap[$backendType])) {
            Logger::log("Unknown backend_type '$backendType', skipping.");
            return null;
        }

        return $tableMap[$backendType];
    }
}