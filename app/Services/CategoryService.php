<?php

namespace MagentoSync\Services;

use MagentoSync\Helpers\Logger;
use Illuminate\Database\Capsule\Manager as DB;

class CategoryService
{
    /**
     * Ensure a category exists under the given root.
     * Returns category_id if successful, null otherwise.
     */
    public static function ensureCategory(string $rootCategoryName, string $childCategoryName, bool $dryRun = false): ?int
    {
        // Resolve root category
        $rootId = self::getRootCategoryId($rootCategoryName);
        if (!$rootId) {
            Logger::log("❌ Root category '{$rootCategoryName}' not found. Skipping child: {$childCategoryName}");
            return null;
        }

        // Check if child already exists under root
        $childId = self::getChildCategoryId($childCategoryName, $rootId);
        if ($childId) {
            Logger::log("✅ Category '{$childCategoryName}' (ID: {$childId}) already exists under '{$rootCategoryName}'.");
            return $childId;
        }

        // Normalize URL key
        $urlKey = self::normalizeUrlKey($childCategoryName);

        // Check for URL rewrite conflict
        $conflict = DB::table('url_rewrite')
            ->where('request_path', $urlKey)
            //->where('entity_type', 'category')
            ->exists();

        if ($conflict) {
            $existingEntity = $conflict->entity_type ?? 'unknown';
            Logger::log("⚠️ URL key '{$urlKey}' is already used by a {$existingEntity}. Skipping category: '{$childCategoryName}'.");
            return null;
        }

        if ($dryRun) {
            Logger::log("[DRY RUN] Would create category: '{$childCategoryName}' under root '{$rootCategoryName}' with url_key={$urlKey}");
            return 99999; // mock ID
        }

        try {
            $categoryId = self::createCategory($childCategoryName, $rootId, $urlKey);
            if ($categoryId) {
                Logger::log("🆕 Created category '{$childCategoryName}' (ID: {$categoryId}) under '{$rootCategoryName}'");
                self::enqueueIndexer('catalog_category_flat_cl', $categoryId);
                self::enqueueIndexer('catalog_category_product_cl', $categoryId);
            }
            return $categoryId;
        } catch (\Exception $e) {
            Logger::log("❌ Failed to create category '{$childCategoryName}': " . $e->getMessage());
            return null;
        }
    }

    private static function getRootCategoryId(string $name): ?int
    {
        return DB::table('catalog_category_entity as ce')
            ->join('catalog_category_entity_varchar as cv', 'ce.entity_id', '=', 'cv.entity_id')
            ->join('eav_attribute as ea', 'cv.attribute_id', '=', 'ea.attribute_id')
            ->where('ea.attribute_code', 'name')
            ->where('cv.store_id', 0)
            ->where('cv.value', $name)
            ->where('ce.level', 1)
            ->value('ce.entity_id');
    }

    private static function getChildCategoryId(string $name, int $parentId): ?int
    {
        return DB::table('catalog_category_entity as ce')
            ->join('catalog_category_entity_varchar as cv', 'ce.entity_id', '=', 'cv.entity_id')
            ->join('eav_attribute as ea', 'cv.attribute_id', '=', 'ea.attribute_id')
            ->where('ea.attribute_code', 'name')
            ->where('cv.store_id', 0)
            ->where('cv.value', $name)
            ->where('ce.parent_id', $parentId)
            ->value('ce.entity_id');
    }

    private static function createCategory(string $name, int $parentId, string $urlKey): ?int
    {
        $now = date('Y-m-d H:i:s');

        try {
            $inserted = DB::table('catalog_category_entity')->insertGetId([
                'parent_id'        => $parentId,
                'created_at'       => $now,
                'updated_at'       => $now,
                'path'             => '', // will be updated after insert
                'position'         => 1,
                'level'            => 2,
                'children_count'   => 0,
            ]);

            if (!$inserted) {
                throw new \Exception("Insert failed to return ID");
            }

            // Update path: {parent_path}/{inserted_id}
            $parentPath = DB::table('catalog_category_entity')
                ->where('entity_id', $parentId)
                ->value('path');

            $path = $parentPath ? "{$parentPath}/{$inserted}" : "{$parentId}/{$inserted}";
            DB::table('catalog_category_entity')
                ->where('entity_id', $inserted)
                ->update(['path' => $path]);

            // Get attribute IDs
            $nameAttrId = DB::table('eav_attribute')
                ->where('entity_type_id', 3) // 3 = catalog_category
                ->where('attribute_code', 'name')
                ->value('attribute_id');

            $urlKeyAttrId = DB::table('eav_attribute')
                ->where('entity_type_id', 3)
                ->where('attribute_code', 'url_key')
                ->value('attribute_id');

            if (!$nameAttrId) {
                throw new \Exception("Attribute ID not found for 'name'");
            }
            if (!$urlKeyAttrId) {
                throw new \Exception("Attribute ID not found for 'url_key'");
            }

            // Insert name (store 0)
            DB::table('catalog_category_entity_varchar')->insert([
                'attribute_id' => $nameAttrId,
                'store_id'     => 0,
                'entity_id'    => $inserted,
                'value'        => $name,
            ]);

            // Insert url_key (store 0)
            DB::table('catalog_category_entity_varchar')->insert([
                'attribute_id' => $urlKeyAttrId,
                'store_id'     => 0,
                'entity_id'    => $inserted,
                'value'        => $urlKey,
            ]);

            // Insert url_rewrite
            DB::table('url_rewrite')->insertOrIgnore([
                'entity_type'      => 'category',
                'entity_id'        => $inserted,
                'request_path'     => $urlKey,
                'target_path'      => "catalog/category/view/id/{$inserted}",
                'redirect_type'    => 0,
                'store_id'         => 1,
                'is_autogenerated' => 1,
                'metadata'         => null,
            ]);

            return $inserted;
        } catch (\Exception $e) {
            Logger::log("Failed to create category: " . $e->getMessage());
            // Clean up on failure?
            // DB::table('catalog_category_entity')->where('entity_id', $inserted ?? null)->delete();
            return null;
        }
    }

    private static function normalizeUrlKey(string $name): string
    {
        return preg_replace('/[^a-z0-9_]+/', '-', strtolower(trim($name, '-')));
    }

    protected static function enqueueIndexer(string $index, int $entityId): void
    {
        try {
            DB::table($index)->insertOrIgnore([
                ['entity_id' => $entityId]
            ]);
        } catch (\Throwable $e) {
            Logger::log("Indexer enqueue fail [$index]: " . $e->getMessage());
        }
    }
}