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

        $cached = Cache::get('attribute_' . $code);
        if ($cached) {
            $attribute = new Attribute($cached);
            self::$cache[$code] = $attribute;
            return $attribute;
        }

        $attribute = Attribute::where('attribute_code', $code)
            ->where('entity_type_id', 4)
            ->first();

        if (!$attribute) {
            Logger::log("Attribute not found: $code");
            return null;
        }

        self::$cache[$code] = $attribute;
        Cache::set('attribute_' . $code, $attribute->toArray());
        return $attribute;
    }

    public static function resolveOption($attributeId, $label) {
        $label = trim($label); // avoid problems with labels with spaces before and after unnecessarily.
        $key = "attr_{$attributeId}_option_" . md5($label);
        if ($cached = Cache::get($key)) return $cached;

        $option = DB::table('eav_attribute_option_value')
            ->join('eav_attribute_option', 'eav_attribute_option.option_id', '=', 'eav_attribute_option_value.option_id')
            ->where('eav_attribute_option.attribute_id', $attributeId)
            ->where('eav_attribute_option_value.value', $label)
            ->first();

        if ($option) {
            Cache::set($key, $option->option_id);
            return $option->option_id;
        }

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