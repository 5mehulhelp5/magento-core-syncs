<?php
namespace MagentoSync\Controllers;

class IndexController
{
    public function handle(float $starttime)
    {
        header('Content-Type: text/html; charset=utf-8');
        return "<h1>OK</h1><p>Elapsed: ".round((microtime(true)-$starttime),3)."s</p>";
    }
}
