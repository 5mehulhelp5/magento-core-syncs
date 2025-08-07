<?php
$starttime = microtime(true);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Config/bootstrap.php';

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$route = ucfirst(camelize($path)); // e.g. mixed_product → MixedProduct

$controllerClass = "MagentoSync\\Controllers\\{$route}Controller";

if (class_exists($controllerClass)) {
    $controller = new $controllerClass();
    echo $controller->handle($starttime);
} else {
    http_response_code(404);
    echo '404 Not Found';
}

function camelize($input, $separator = '_') {
    return str_replace($separator, '', ucwords($input, $separator));
}