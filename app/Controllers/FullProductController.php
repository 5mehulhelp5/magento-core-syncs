<?php
namespace MagentoSync\Controllers;

use MagentoSync\Services\ProductService;

class FullProductController {
    public function handle($starttime = null) {
        $starttime = $starttime ?? microtime(true);
        $sample = ['sku' => 'Test_', 'name' => 'Test_ Test Product 0', 'price' => '167.61', 'attribute_set' => 'Defaulter'];
        ProductService::upsert($sample);
        $duration = round(microtime(true) - $starttime, 2);
        return "Product processed in {$duration}s";
    }
}