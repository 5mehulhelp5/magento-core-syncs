<?php
namespace MagentoSync\Helpers;

class Validator
{
    public function isValidSku(string $sku): bool
    {
        // keep old logic; no framework deps needed
        return $sku !== '' && strlen($sku) <= 64;
    }
}
