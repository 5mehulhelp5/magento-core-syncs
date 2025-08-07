<?php
namespace MagentoSync\Controllers;

use MagentoSync\Services\ProductService;

class PartialProductController {
    public function handle($starttime = null) {
        $starttime = $starttime ?? microtime(true);
        $sample = [
            'sku' => 'demo-sku-456',
            'qty' => 5
        ];

        ProductService::upsert($sample);
        $duration = round(microtime(true) - $starttime, 2);
        return "Partial product processed in {$duration}s";
    }
}