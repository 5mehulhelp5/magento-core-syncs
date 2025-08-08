<?php
namespace MagentoSync\Services;

use MagentoSync\Models\Product;
use MagentoSync\Models\Stock;
use MagentoSync\Helpers\Logger;
use MagentoSync\Services\UrlRewriteService;
use MagentoSync\Services\ActivityLogger;
use MagentoSync\Services\AttributeResolverService;
use MagentoSync\Services\CategoryService;
use Illuminate\Database\Capsule\Manager as DB;

class ProductService {
    public static function upsert(array $data, bool $dryRun = false) {
        $attributeSetId = self::resolveAttributeSetId($data['attribute_set'] ?? null);

        $product = Product::where('sku', $data['sku'])->first();
        $isNew = false;

        if (!$product) {
            if ($dryRun) {
                Logger::log("[DRY RUN] Would create new product: {$data['sku']} with attribute_set_id=$attributeSetId");
                return;
            }
            $product = Product::create([
                'sku' => $data['sku'],
                'type_id' => 'simple',
                'attribute_set_id' => $attributeSetId,
            ]);
            $isNew = true;
        }

        if (isset($data['qty'])) {
            if ($dryRun) {
                Logger::log("[DRY RUN] Would update stock for SKU: {$data['sku']} to qty={$data['qty']}");
            } else {
                $stock = Stock::firstOrNew(['product_id' => $product->entity_id]);
                $stock->fill([
                    'qty' => $data['qty'],
                    'is_in_stock' => $data['qty'] > 0 ? 1 : 0,
                ])->save();
                self::enqueueIndexer('cataloginventory_stock_cl', $product->entity_id);
            }
        }

        foreach ($data as $code => $value) {
            if (in_array($code, ['sku', 'qty', 'attribute_set'])) continue;
            if ($code === 'url_key') {
                $finalUrlKey = UrlRewriteService::process($product->entity_id, $data['sku'], $data['name'], $value);
                Logger::log("✅ URL key registered: $finalUrlKey");
            }
            $attribute = AttributeResolverService::resolve($code);
            if (!$attribute) continue;

            if ($attribute->frontend_input === 'select') {
                $optionId = AttributeResolverService::resolveOption($attribute->attribute_id, $value);
                if ($dryRun) {
                    Logger::log("[DRY RUN] Would update select attribute [$code] with option_id=$optionId");
                } else {
                    try {
                        DB::table("catalog_product_entity_int")->updateOrInsert([
                            'entity_id' => $product->entity_id,
                            'attribute_id' => $attribute->attribute_id,
                            'store_id' => 0,
                        ], [
                            'value' => $optionId
                        ]);
                    } catch (\Throwable $e) {
                        Logger::log("DB FAIL [catalog_product_entity_int] for attribute '{$code}': " . $e->getMessage());
                    }
                }
            } else {
                $tableMap = [
                    'varchar' => 'catalog_product_entity_varchar',
                    'int' => 'catalog_product_entity_int',
                    'text' => 'catalog_product_entity_text',
                    'decimal' => 'catalog_product_entity_decimal',
                    'datetime' => 'catalog_product_entity_datetime'
                ];
                $table = $tableMap[$attribute->backend_type] ?? 'catalog_product_entity_varchar';

                if ($dryRun) {
                    Logger::log("[DRY RUN] Would update $table for [$code] with value=$value");
                } else {
                    try {
                        DB::table($table)->updateOrInsert([
                            'entity_id' => $product->entity_id,
                            'attribute_id' => $attribute->attribute_id,
                            'store_id' => 0,
                        ], [
                            'value' => $value
                        ]);

                        if (in_array($code, ['price', 'special_price'])) {
                            self::enqueueIndexer('catalog_product_price_cl', $product->entity_id);
                            self::enqueueIndexer('catalogrule_product_cl', $product->entity_id);
                        }
                        if (in_array($code, ['name', 'description'])) {
                            self::enqueueIndexer('catalogsearch_fulltext_cl', $product->entity_id);
                        }
                    } catch (\Throwable $e) {
                        Logger::log("DB FAIL [$table] for attribute '{$code}': " . $e->getMessage());
                    }
                }
            }
        }

        // Handle categories
        if (isset($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $categoryMap) {
                foreach ($categoryMap as $rootName => $childName) {
                    if ($dryRun) {
                        Logger::log("[DRY RUN] Would assign product {$data['sku']} to category: {$childName} under root={$rootName}");
                    } else {
                        $categoryId = CategoryService::ensureCategory($rootName, $childName, $dryRun);
                        if ($categoryId) {
                            try {
                                DB::table('catalog_category_product')->insertOrIgnore([
                                    'category_id' => $categoryId,
                                    'product_id'  => $product->entity_id,
                                    'position'    => 0,
                                ]);
                                Logger::log("🔗 Assigned product {$data['sku']} to category ID: {$categoryId}");
                            } catch (\Throwable $e) {
                                Logger::log("❌ Failed to assign product {$data['sku']} to category: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        if (!$dryRun) {
            ActivityLogger::logDb('admin', $isNew ? 'new' : 'edit', $data['sku']);
            Logger::log("Product {$data['sku']} synced with attributes.");
        } else {
            Logger::log("[DRY RUN] Product {$data['sku']} dry-run completed.");
        }
    }

    protected static function resolveAttributeSetId(?string $label = null): int {
        try {
            if ($label) {
                $setId = DB::table('eav_attribute_set')
                    ->where('entity_type_id', 4)
                    ->where('attribute_set_name', $label)
                    ->value('attribute_set_id');
                if ($setId) {
                    return $setId;
                }else{
                    Logger::log("No such attribute set with label={$label}");
                }
            }
        } catch (\Throwable $e) {
            Logger::log("Failed to resolve attribute_set_id for label={$label}: {$e->getMessage()}");
        }

        return (int) DB::table('eav_attribute_set')
            ->where('entity_type_id', 4)
            ->orderBy('attribute_set_id', 'asc')
            ->value('attribute_set_id') ?? 12;
    }

    protected static function enqueueIndexer(string $index, int $productId): void {
        try {
            DB::table($index)->insertOrIgnore([
                ['entity_id' => $productId]
            ]);
        } catch (\Throwable $e) {
            Logger::log("Indexer enqueue fail [$index]: " . $e->getMessage());
        }
    }
}