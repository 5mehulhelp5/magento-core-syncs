<?php
namespace MagentoSync\Controllers;

use MagentoSync\Services\ProductService;

class MixedProductController {
    public function handle($starttime = null) {
        $starttime = $starttime ?? microtime(true);
        $sample = [
            ['sku' => 'demo-sku-789', 'qty' => 3],
            ['sku' => 'demo-sku-999', 'qty' => 0],
        ];

        foreach ($sample as $product) {
            ProductService::upsert($product);
        }
        $duration = round(microtime(true) - $starttime, 2);
        return "Mixed product processed in {$duration}s";
    }
}