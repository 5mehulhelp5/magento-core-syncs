<?php

// ===== temporary diagnostics =====
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo $e;
});
set_error_handler(function ($s, $m, $f, $l) {
    http_response_code(500);
    echo "$m @ $f:$l";
    return true;
});
// ===== end temporary =====

$starttime = microtime(true);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Config/bootstrap.php';

// ----------------------------
// Parse segments (CLI vs Web)
// ----------------------------
if (php_sapi_name() === 'cli') {
    // CLI: php public/index.php cron run ...
    $segments = array_slice($argv, 1);
} else {
    // Web: /foo/bar -> ['foo','bar']
    $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $segments = $path !== '' ? explode('/', $path) : [];
}

// ----------------------------
// Admin prefix detection
// ----------------------------
$adminPrefix = defined('ADMIN_PATH') ? ADMIN_PATH : 'admin';
$isAdmin = (!empty($segments) && strcasecmp($segments[0], $adminPrefix) === 0);
if ($isAdmin) {
    array_shift($segments); // drop admin prefix
}

// ----------------------------
// Resolve controller + action
// ----------------------------
if (empty($segments) || strtolower($segments[0]) === 'index') {
    $route = 'Index';
} else {
    $route = ucfirst(camelize($segments[0])); // e.g. mixed_product -> MixedProduct
}

// Build FQCN: MagentoSync\Controllers\[Admin\]{$route}Controller
$baseNs = 'MagentoSync\\Controllers';
$controllerClass = $isAdmin
    ? $baseNs . '\\Admin\\' . $route . 'Controller'
    : $baseNs . '\\' . $route . 'Controller';

// ----------------------------
// Dispatch
// ----------------------------
if (class_exists($controllerClass)) {
    $controller = new $controllerClass();

    // Pass remaining segments (after controller) to handle()
    // Example:
    //   /secret_admin/ajax/index  -> handle($start, ['index'])
    //   /cron/run                 -> handle($start, ['run'])
    echo $controller->handle($starttime, array_slice($segments, 1));
} else {
    http_response_code(404);
    echo '404 Not Found' . $controllerClass;
}

// ----------------------------
// Helpers
// ----------------------------
function camelize($input, $separator = '_')
{
    return str_replace($separator, '', ucwords($input, $separator));
}
